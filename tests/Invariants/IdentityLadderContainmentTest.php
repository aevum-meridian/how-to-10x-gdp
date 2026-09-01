<?php

// SPDX-License-Identifier: LicenseRef-MVL-2.0 AND LicenseRef-AVL-2.0

declare(strict_types=1);

namespace Tests\Invariants;

use App\Domain\Identity\Enums\AppealStatus;
use App\Domain\Identity\Exceptions\ConstitutionalCapException;
use App\Domain\Identity\Ladder\AttestationVestingService;
use App\Domain\Identity\Ladder\Data\SybilScore;
use App\Domain\Identity\Ladder\Rung1PoolGovernor;
use App\Domain\Identity\Ladder\SybilBountyRegistry;
use App\Domain\Identity\Ladder\SybilGraphAnalyzer;
use App\Domain\Identity\Models\ConstitutionalParameter;
use App\Domain\Identity\Models\Identity;
use App\Domain\Identity\Models\Rung1PoolGrant;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * IdentityLadderContainmentTest — DOCUMENT 6.2 §0/§2.
 *
 * The ladder's honest resolution of the inclusion-vs-Sybil trade-off:
 * BOUND the loss. The Rung-1 pool's hard constitutional cap is enforced
 * at the SERVICE layer and independently at the DATABASE layer; the cap
 * itself cannot change without a fresh amendment reference; vesting
 * ramps and RESETS on slash; Sybil scoring is explainable, appealable,
 * cluster-targeted — a throttle, never an exclusion.
 */
final class IdentityLadderContainmentTest extends TestCase
{
    private Rung1PoolGovernor $governor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->governor = new Rung1PoolGovernor();
    }

    private function identity(int $rung = 1): Identity
    {
        $identity = new Identity([
            'subject_commitment' => 'commit:'.Str::ulid(),
            'aggregation_version' => 'agg-v1.0',
            'effective_rung' => $rung,
            'provider_attestations' => [],
            'appeal_status' => AppealStatus::None,
            'explanation' => 'test identity',
        ]);
        $identity->save();

        return $identity;
    }

    private function setCap(int $capMinor): void
    {
        $existing = ConstitutionalParameter::query()->find(Rung1PoolGovernor::CAP_PARAMETER_KEY);

        if ($existing !== null) {
            // Lawful amendment: a fresh reference.
            $existing->value_minor = $capMinor;
            $existing->amendment_ref = 'amendment:'.Str::ulid();
            $existing->save();

            return;
        }

        $cap = new ConstitutionalParameter([
            'key' => Rung1PoolGovernor::CAP_PARAMETER_KEY,
            'value_minor' => $capMinor,
            'amendment_ref' => 'amendment:'.Str::ulid(),
        ]);
        $cap->save();
    }

    public function test_the_pool_fails_closed_without_a_constitutional_cap(): void
    {
        $identity = $this->identity();

        // Service layer.
        try {
            $this->governor->grant($identity, 100, 'grant:'.Str::ulid());
            $this->fail('A grant without a defined cap must be refused.');
        } catch (ConstitutionalCapException $e) {
            $this->assertStringContainsString('fails CLOSED', $e->getMessage());
        }

        // DB layer, bypassing the service entirely.
        try {
            DB::table('rung1_pool_grants')->insert([
                'id' => strtolower((string) Str::ulid()),
                'identity_id' => $identity->id,
                'amount_minor' => 100,
                'idempotency_key' => 'grant:'.Str::ulid(),
                'granted_at' => now(),
            ]);
            $this->fail('The DB trigger must refuse a grant when no cap is defined.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('fails CLOSED', $e->getMessage());
        }

        $this->assertSame(0, Rung1PoolGrant::query()->count());
    }

    public function test_the_hard_cap_bounds_lifetime_issuance_at_both_layers(): void
    {
        $this->setCap(1_000);
        $identity = $this->identity();

        // Fill the pool exactly to the cap.
        $this->governor->grant($identity, 600, 'grant:a:'.Str::ulid());
        $this->governor->grant($identity, 400, 'grant:b:'.Str::ulid());

        $this->assertSame(0, $this->governor->remainingBudgetMinor());

        // Service layer refuses one unit over.
        try {
            $this->governor->grant($identity, 1, 'grant:c:'.Str::ulid());
            $this->fail('A grant beyond the cap must be refused by the service.');
        } catch (ConstitutionalCapException $e) {
            $this->assertStringContainsString('hard constitutional cap', $e->getMessage());
        }

        // DB layer refuses the same, independently.
        try {
            DB::table('rung1_pool_grants')->insert([
                'id' => strtolower((string) Str::ulid()),
                'identity_id' => $identity->id,
                'amount_minor' => 1,
                'idempotency_key' => 'grant:d:'.Str::ulid(),
                'granted_at' => now(),
            ]);
            $this->fail('The DB trigger must refuse a grant beyond the cap.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('hard constitutional cap', $e->getMessage());
        }

        $this->assertSame(1_000, (int) Rung1PoolGrant::query()->sum('amount_minor'));
    }

    public function test_randomized_grant_sequences_never_pierce_the_cap(): void
    {
        mt_srand(20260814);
        $cap = 50_000;
        $this->setCap($cap);
        $identity = $this->identity();

        $granted = 0;
        $refused = 0;

        for ($i = 0; $i < 60; $i++) {
            $amount = mt_rand(500, 3_000);

            try {
                $this->governor->grant($identity, $amount, "grant:rand:{$i}");
                $granted += $amount;
            } catch (ConstitutionalCapException) {
                $refused++;
            }

            // The invariant, checked after every step: lifetime issuance
            // never exceeds the cap.
            $issued = (int) Rung1PoolGrant::query()->sum('amount_minor');
            $this->assertLessThanOrEqual($cap, $issued);
            $this->assertSame($granted, $issued);
        }

        $this->assertGreaterThan(0, $refused, 'The sequence should have hit the cap at least once.');

        // Replay of an already-granted key is a no-op, not a second grant.
        $before = (int) Rung1PoolGrant::query()->sum('amount_minor');
        $first = Rung1PoolGrant::query()->where('idempotency_key', 'grant:rand:0')->firstOrFail();
        $replayed = $this->governor->grant($identity, 999_999, 'grant:rand:0');
        $this->assertSame($first->id, $replayed->id);
        $this->assertSame($before, (int) Rung1PoolGrant::query()->sum('amount_minor'));
    }

    public function test_the_cap_parameter_is_amendable_only_with_a_fresh_reference(): void
    {
        $this->setCap(10_000);

        // A raw value rewrite with the same amendment ref is refused.
        try {
            DB::table('constitutional_parameters')
                ->where('key', Rung1PoolGovernor::CAP_PARAMETER_KEY)
                ->update(['value_minor' => 999_999_999]);
            $this->fail('A silent cap change must be refused by the DB trigger.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('constitutional process', $e->getMessage());
        }

        // Deletion is refused outright.
        try {
            DB::table('constitutional_parameters')
                ->where('key', Rung1PoolGovernor::CAP_PARAMETER_KEY)
                ->delete();
            $this->fail('Deleting a constitutional parameter must be refused.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('never deleted', $e->getMessage());
        }

        // A lawful amendment (new ref) succeeds and leaves its mark.
        DB::table('constitutional_parameters')
            ->where('key', Rung1PoolGovernor::CAP_PARAMETER_KEY)
            ->update(['value_minor' => 20_000, 'amendment_ref' => 'amendment:lawful:'.Str::ulid()]);

        $cap = ConstitutionalParameter::query()->findOrFail(Rung1PoolGovernor::CAP_PARAMETER_KEY);
        $this->assertSame(20_000, $cap->value_minor);
        $this->assertStringContainsString('lawful', $cap->amendment_ref);
    }

    public function test_grants_are_append_only_bookkeeping(): void
    {
        $this->setCap(5_000);
        $identity = $this->identity();
        $grant = $this->governor->grant($identity, 1_000, 'grant:'.Str::ulid());

        // Rewriting a grant would un-spend the budget — refused.
        try {
            DB::table('rung1_pool_grants')->where('id', $grant->id)->update(['amount_minor' => 1]);
            $this->fail('Grant rewrite must be refused.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('append-only', $e->getMessage());
        }

        try {
            DB::table('rung1_pool_grants')->where('id', $grant->id)->delete();
            $this->fail('Grant deletion must be refused.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('append-only', $e->getMessage());
        }
    }

    public function test_rung0_holds_and_swaps_but_the_dividend_pool_requires_attestation(): void
    {
        $this->setCap(5_000);
        $rung0 = $this->identity(rung: 0);

        try {
            $this->governor->grant($rung0, 100, 'grant:'.Str::ulid());
            $this->fail('The probationary pool requires Rung 1.');
        } catch (ConstitutionalCapException $e) {
            // The refusal is explanatory, not a bare no: it names what
            // Rung 0 CAN do and what would change the outcome.
            $this->assertStringContainsString('hold, swap', $e->getMessage());
            $this->assertStringContainsString('social attestation', $e->getMessage());
        }
    }

    public function test_vesting_ramps_toward_full_value_and_resets_on_slash(): void
    {
        $identity = $this->identity();
        $service = new AttestationVestingService();
        $vesting = $service->startVesting($identity, vestingDays: 90);

        // Fresh identity: no vested value.
        $this->assertSame(0.0, $service->vestedMultiplier($vesting, $vesting->vesting_started_at->copy()));

        // Monotone non-decreasing ramp, capped at 1.0.
        $previous = 0.0;

        foreach ([1, 10, 30, 45, 60, 89, 90, 120] as $day) {
            $at = $vesting->vesting_started_at->copy()->addDays($day);
            $multiplier = $service->vestedMultiplier($vesting, $at);

            $this->assertGreaterThanOrEqual($previous, $multiplier);
            $this->assertLessThanOrEqual(1.0, $multiplier);
            $previous = $multiplier;
        }

        $this->assertSame(1.0, $service->vestedMultiplier($vesting, $vesting->vesting_started_at->copy()->addDays(90)));

        // Halfway through: about half vested.
        $half = $service->vestedMultiplier($vesting, $vesting->vesting_started_at->copy()->addDays(45));
        $this->assertEqualsWithDelta(0.5, $half, 0.01);

        // Slash: the ramp RESETS — a Sybil cannot keep its accrued trust.
        Carbon::setTestNow($vesting->vesting_started_at->copy()->addDays(45));

        try {
            $slashed = $service->slash($vesting);
            $this->assertSame(1, $slashed->slash_count);
            $this->assertSame(0.0, $service->vestedMultiplier($slashed, Carbon::now()));

            // And re-earns from zero on the same schedule.
            $this->assertEqualsWithDelta(
                0.5,
                $service->vestedMultiplier($slashed, Carbon::now()->copy()->addDays(45)),
                0.01,
            );
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_sybil_scoring_targets_clusters_and_is_always_explained(): void
    {
        $analyzer = new SybilGraphAnalyzer();

        // A genuine but unusual person: few attesters, none shared.
        $genuine = $analyzer->score('id-genuine', ['att-1', 'att-2'], [
            'id-x' => ['att-9', 'att-10'],
            'id-y' => ['att-11'],
        ]);
        $this->assertSame(0.0, $genuine->score);
        $this->assertTrue($genuine->appealable);
        $this->assertStringContainsString('independent', implode(' ', $genuine->reasons));

        // A farm: siblings sharing the same attesters, sealed loop.
        $farmed = $analyzer->score('id-farm-1', ['id-farm-2', 'id-farm-3'], [
            'id-farm-2' => ['id-farm-1', 'id-farm-3'],
            'id-farm-3' => ['id-farm-1', 'id-farm-2'],
        ]);
        $this->assertGreaterThan(0.5, $farmed->score);
        $this->assertTrue($farmed->appealable);

        // Explainability is structural: reasons name the signal AND what
        // would change the outcome.
        $explanation = implode(' ', $farmed->reasons);
        $this->assertStringContainsString('What would change the outcome', $explanation);

        // An unexplained score cannot even be constructed.
        try {
            new SybilScore(identityId: 'x', score: 0.9, reasons: [], appealable: true);
            $this->fail('A score without reasons is a black-box exclusion and must be unconstructible.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('black-box', $e->getMessage());
        }

        // Determinism: same graph, same score.
        $again = $analyzer->score('id-farm-1', ['id-farm-2', 'id-farm-3'], [
            'id-farm-2' => ['id-farm-1', 'id-farm-3'],
            'id-farm-3' => ['id-farm-1', 'id-farm-2'],
        ]);
        $this->assertSame($farmed->score, $again->score);
        $this->assertSame($farmed->reasons, $again->reasons);
    }

    public function test_the_bounty_hunts_farms_never_people(): void
    {
        $registry = new SybilBountyRegistry();

        // A report naming a single identity is refused.
        try {
            $registry->report('reporter:'.Str::ulid(), ['lone-identity'], ['note' => 'suspicious']);
            $this->fail('Single-identity reports must be refused.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('never individuals', $e->getMessage());
        }

        // A cluster report stands, and resolution requires a public note.
        $bounty = $registry->report('reporter:'.Str::ulid(), ['id-a', 'id-b', 'id-c'], ['graph' => 'closed loop']);
        $this->assertSame('open', $bounty->status);

        try {
            $registry->resolve($bounty, award: true, resolutionNote: '   ');
            $this->fail('A resolution without a public note must be refused.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('public note', $e->getMessage());
        }

        $resolved = $registry->resolve($bounty, award: true, resolutionNote: 'Cluster confirmed by graph audit.');
        $this->assertSame('awarded', $resolved->status);

        // Resolution is terminal.
        try {
            $registry->resolve($resolved, award: false, resolutionNote: 'flip');
            $this->fail('A resolved bounty cannot be re-resolved.');
        } catch (\DomainException $e) {
            $this->assertStringContainsString('already been resolved', $e->getMessage());
        }
    }

    public function test_containment_never_debits_any_balance(): void
    {
        // The whole containment layer — governor, vesting, analyzer,
        // bounty — must be structurally incapable of touching the
        // ledger. Wall scan over the ladder + recovery sources.
        $forbidden = [
            'LedgerService',
            'DisputeService',
            'IssuanceService',
            'EntryDraft',
            'TransactionDraft',
            '->post(',
            '->persist(',
            'INSERT INTO entries',
            'INSERT INTO transactions',
        ];

        $roots = [
            app_path('Domain/Identity/Ladder'),
            app_path('Domain/Identity/Recovery'),
            app_path('Domain/Identity/Aggregation'),
        ];

        $scanned = 0;

        foreach ($roots as $root) {
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));

            foreach ($iterator as $file) {
                if (! $file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
                    continue;
                }

                $scanned++;
                $source = (string) file_get_contents($file->getPathname());

                foreach ($forbidden as $token) {
                    $this->assertFalse(
                        str_contains($source, $token),
                        "I6 wall: {$file->getPathname()} references the ledger-write path via \"{$token}\" — "
                        .'Sybil containment bounds future entitlement; it never touches existing credits.'
                    );
                }
            }
        }

        $this->assertGreaterThanOrEqual(6, $scanned);
    }
}
