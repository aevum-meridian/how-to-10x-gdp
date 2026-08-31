<?php

// SPDX-License-Identifier: LicenseRef-MVL-2.0

declare(strict_types=1);

namespace Tests\Invariants;

use App\Domain\Meridian\Ledger\Models\Account;
use App\Domain\Meridian\Settlement\Data\SettlementLeg;
use App\Domain\Meridian\Settlement\Exceptions\SettlementAbortedException;
use App\Domain\Meridian\Settlement\Services\SettlementCoordinator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\LedgerFixtures;
use Tests\TestCase;

/**
 * SettlementAbortTest — DEV-7.x, the abort-path guarantee (I6/I7/I10).
 *
 * DOCUMENT 7.x §3, the crux of the crux: an atomic abort restores
 * prior state exactly and never produces a net debit against a
 * personal balance the holder did not authorize. Across randomized
 * commit/abort, failure injection at every leg boundary, coordinator
 * failure, and timeout — no abort leaves a net unauthorized debit.
 *
 * "Assume a malicious implementer tried to hide a punitive debit in
 * the abort path, and verify they could not."
 */
final class SettlementAbortTest extends TestCase
{
    private SettlementCoordinator $coordinator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->coordinator = app(SettlementCoordinator::class);
    }

    /** @return array{0: \App\Domain\Meridian\Ledger\Models\Currency, 1: Account, 2: Account, 3: Account} */
    private function world(): array
    {
        $currency = LedgerFixtures::currency();
        $alice = LedgerFixtures::personalAccount($currency);
        $bob = LedgerFixtures::personalAccount($currency);
        $carol = LedgerFixtures::personalAccount($currency);
        LedgerFixtures::mint($alice, 100_000);
        LedgerFixtures::mint($bob, 100_000);
        LedgerFixtures::mint($carol, 100_000);

        return [$currency, $alice, $bob, $carol];
    }

    /** @return array<string, int> */
    private function balances(Account ...$accounts): array
    {
        $out = [];
        foreach ($accounts as $account) {
            /** @var object{balance_minor: int} $row */
            $row = DB::selectOne('SELECT balance_minor FROM accounts WHERE id = ?', [$account->id]);
            $out[$account->id] = (int) $row->balance_minor;
        }

        return $out;
    }

    public function test_a_settlement_missing_one_holder_authorization_never_starts_writing(): void
    {
        [$currency, $alice, $bob, $carol] = $this->world();
        $before = $this->balances($alice, $bob, $carol);
        $entriesBefore = (int) DB::table('entries')->count();

        // Two legs authorized, the third not: I10 pre-verification
        // refuses the WHOLE settlement before any write.
        try {
            $this->coordinator->settle('s-'.Str::random(8), [
                new SettlementLeg($alice->id, $bob->id, $currency->id, 10_000, 'auth:alice:1'),
                new SettlementLeg($bob->id, $carol->id, $currency->id, 5_000, 'auth:bob:1'),
                new SettlementLeg($carol->id, $alice->id, $currency->id, 2_500, null),
            ]);
            $this->fail('I10 violated: a settlement debiting an unauthorized personal account ran.');
        } catch (SettlementAbortedException $e) {
            $this->assertStringContainsString('I10', $e->getMessage());
        }

        $this->assertSame($before, $this->balances($alice, $bob, $carol));
        $this->assertSame($entriesBefore, (int) DB::table('entries')->count());
    }

    public function test_failure_injected_at_every_leg_boundary_aborts_with_zero_residue(): void
    {
        [$currency, $alice, $bob, $carol] = $this->world();

        // Inject a coordinator failure after leg 0, then after leg 1,
        // then after leg 2 — every partial-state point there is.
        foreach ([0, 1, 2] as $failAfterLeg) {
            $before = $this->balances($alice, $bob, $carol);
            $entriesBefore = (int) DB::table('entries')->count();
            $ref = 's-'.Str::random(8);

            try {
                $this->coordinator->settle($ref, [
                    new SettlementLeg($alice->id, $bob->id, $currency->id, 10_000, 'auth:alice:'.$ref),
                    new SettlementLeg($bob->id, $carol->id, $currency->id, 5_000, 'auth:bob:'.$ref),
                    new SettlementLeg($carol->id, $alice->id, $currency->id, 2_500, 'auth:carol:'.$ref),
                ], chaos: static function (int $leg) use ($failAfterLeg): void {
                    if ($leg === $failAfterLeg) {
                        throw new \RuntimeException("injected coordinator failure after leg {$leg}");
                    }
                });
                $this->fail('The injected failure did not abort the settlement.');
            } catch (SettlementAbortedException $e) {
                $this->assertStringContainsString('Prior state is restored exactly', $e->getMessage());
            }

            // Bit-for-bit restoration: balances AND entry count.
            $this->assertSame(
                $before,
                $this->balances($alice, $bob, $carol),
                "Abort after leg {$failAfterLeg} left a balance changed."
            );
            $this->assertSame(
                $entriesBefore,
                (int) DB::table('entries')->count(),
                "Abort after leg {$failAfterLeg} left residual entries."
            );
            $this->assertSame(0, (int) DB::table('transactions')
                ->where('idempotency_key', 'like', "settle:{$ref}:%")->count());
        }
    }

    public function test_randomized_commit_abort_sequences_never_leave_a_net_unauthorized_debit(): void
    {
        [$currency, $alice, $bob, $carol] = $this->world();
        $accounts = [$alice, $bob, $carol];

        mt_srand(20260810);

        for ($round = 0; $round < 25; $round++) {
            $before = $this->balances($alice, $bob, $carol);
            $ref = 's-'.Str::random(8);

            $legCount = mt_rand(1, 3);
            $legs = [];
            $authorized = [];

            for ($i = 0; $i < $legCount; $i++) {
                $from = $accounts[mt_rand(0, 2)];
                $to = $accounts[mt_rand(0, 2)];
                if ($to->id === $from->id) {
                    $to = $accounts[($from === $alice) ? 1 : 0];
                }

                // 20% of legs "forget" the authorization — adversarial.
                $withAuth = mt_rand(1, 10) > 2;
                $legs[] = new SettlementLeg(
                    $from->id,
                    $to->id,
                    $currency->id,
                    mt_rand(1, 2_000),
                    $withAuth ? "auth:{$from->id}:{$ref}:{$i}" : null,
                );
                $authorized[] = $withAuth;
            }

            // 30% of rounds inject a mid-flight coordinator failure.
            $failAt = mt_rand(1, 10) <= 3 ? mt_rand(0, $legCount - 1) : null;
            $chaos = $failAt === null ? null : static function (int $leg) use ($failAt): void {
                if ($leg === $failAt) {
                    throw new \RuntimeException('randomized injected failure');
                }
            };

            try {
                $result = $this->coordinator->settle($ref, $legs, $chaos);

                // Commit path: every leg must have been authorized and
                // no failure injected before completion.
                $this->assertNotContains(
                    false,
                    $authorized,
                    'A settlement with an unauthorized personal debit committed.'
                );
                $this->assertNull($failAt, 'A settlement with an injected failure committed.');
                $this->assertCount($legCount, $result->transactionIds);

                // Every debit entry in the settlement carries its
                // holder's authorization (the whole-surface property).
                $unauthorizedDebits = (int) DB::table('entries')
                    ->join('transactions', 'transactions.id', '=', 'entries.transaction_id')
                    ->join('accounts', 'accounts.id', '=', 'entries.account_id')
                    ->where('transactions.idempotency_key', 'like', "settle:{$ref}:%")
                    ->where('entries.amount', '<', 0)
                    ->where('accounts.owner_type', 'person')
                    ->whereNull('entries.holder_authorization_ref')
                    ->count();
                $this->assertSame(0, $unauthorizedDebits);
            } catch (SettlementAbortedException) {
                // Abort path: prior state exactly, zero residue.
                $this->assertSame(
                    $before,
                    $this->balances($alice, $bob, $carol),
                    "Round {$round}: abort left a balance changed."
                );
                $this->assertSame(
                    0,
                    (int) DB::table('transactions')
                    ->where('idempotency_key', 'like', "settle:{$ref}:%")->count(),
                    "Round {$round}: abort left transactions behind."
                );
            }
        }

        // Conservation across the whole storm: total personal holdings
        // unchanged by any mix of commits and aborts (transfers move,
        // never create or destroy).
        $after = $this->balances($alice, $bob, $carol);
        $this->assertSame(array_sum([100_000, 100_000, 100_000]), array_sum($after));
    }

    public function test_a_timeout_mid_settlement_aborts_cleanly(): void
    {
        [$currency, $alice, $bob] = $this->world();
        $before = $this->balances($alice, $bob);
        $ref = 's-'.Str::random(8);

        try {
            $this->coordinator->settle($ref, [
                new SettlementLeg($alice->id, $bob->id, $currency->id, 10_000, 'auth:alice:'.$ref),
                new SettlementLeg($bob->id, $alice->id, $currency->id, 5_000, 'auth:bob:'.$ref),
            ], chaos: static function (int $leg): void {
                if ($leg === 0) {
                    // A timeout is just a failure that took longer.
                    throw new \RuntimeException('deadline exceeded waiting for counterparty rail');
                }
            });
            $this->fail('The timeout did not abort the settlement.');
        } catch (SettlementAbortedException $e) {
            $this->assertStringContainsString('deadline exceeded', $e->getMessage());
        }

        $this->assertSame($before, $this->balances($alice, $bob));
    }

    public function test_the_malicious_implementer_cannot_hide_a_punitive_debit_in_the_abort_path(): void
    {
        // The mandated adversarial review: attempt, from INSIDE the
        // chaos hook (the abort path's injection point), to post a
        // punitive debit against a personal account while the abort
        // unwinds. Every attempt must fail and leave nothing.
        [$currency, $alice, $bob] = $this->world();
        $before = $this->balances($alice, $bob);
        $ref = 's-'.Str::random(8);

        try {
            $this->coordinator->settle($ref, [
                new SettlementLeg($alice->id, $bob->id, $currency->id, 1_000, 'auth:alice:'.$ref),
            ], chaos: static function (int $leg) use ($alice, $currency): void {
                // Attempt 1: raw unauthorized debit inside the doomed
                // transaction — the I6/I10 trigger rejects it, and even
                // if it had been accepted, the rollback would erase it.
                try {
                    $txnId = strtolower((string) Str::ulid());
                    DB::table('transactions')->insert([
                        'id' => $txnId,
                        'kind' => 'settlement',
                        'status' => 'posted',
                        'idempotency_key' => 'malice:'.$txnId,
                        'metadata' => '{}',
                        'posted_at' => now(),
                        'created_at' => now(),
                    ]);
                    DB::table('entries')->insert([
                        'id' => strtolower((string) Str::ulid()),
                        'transaction_id' => $txnId,
                        'account_id' => $alice->id,
                        'currency_id' => $currency->id,
                        'amount' => -50_000,
                        'balance_after' => 0,
                        'holder_authorization_ref' => null,
                    ]);
                } catch (\Throwable) {
                    // Expected: the guard fired. Now fail the settlement
                    // so the abort path runs with our attempt inside it.
                }

                throw new \RuntimeException('malicious implementer aborts after attempting a hidden debit');
            });
            $this->fail('The settlement did not abort.');
        } catch (SettlementAbortedException) {
            // The abort ran with a hostile payload inside it.
        }

        // NOTHING survived: no balance moved, no malice transaction, no
        // settlement transaction.
        $this->assertSame($before, $this->balances($alice, $bob));
        $this->assertSame(0, (int) DB::table('transactions')
            ->where('idempotency_key', 'like', 'malice:%')->count());
        $this->assertSame(0, (int) DB::table('transactions')
            ->where('idempotency_key', 'like', "settle:{$ref}:%")->count());
    }

    public function test_the_settlement_layer_has_no_punitive_capability_of_any_kind(): void
    {
        // I6/I7 inheritance, stated structurally: the coordinator's
        // whole public surface is settle(); no method reaches the
        // arbitration path or any debit-without-authorization path.
        $reflection = new \ReflectionClass(SettlementCoordinator::class);
        $publicMethods = array_map(
            static fn (\ReflectionMethod $m): string => $m->getName(),
            array_filter(
                $reflection->getMethods(\ReflectionMethod::IS_PUBLIC),
                static fn (\ReflectionMethod $m): bool => ! $m->isConstructor(),
            ),
        );
        $this->assertSame(['settle'], array_values($publicMethods));

        // And its source never speaks the arbitration vocabulary.
        $source = (string) file_get_contents(
            app_path('Domain/Meridian/Settlement/Services/SettlementCoordinator.php')
        );
        foreach (['ArbitrationReversal', 'applyArbitrationReversal', 'arbitration_reversal'] as $token) {
            $this->assertFalse(
                str_contains($source, $token),
                "I6 violated: the settlement layer references \"{$token}\"."
            );
        }
    }
}
