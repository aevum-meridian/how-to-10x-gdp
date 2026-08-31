<?php

// SPDX-License-Identifier: LicenseRef-AVL-2.0

declare(strict_types=1);

namespace Tests\Invariants;

use App\Domain\Aevum\Fabric\Data\Asset;
use App\Domain\Aevum\Fabric\Data\ExperienceRegistration;
use App\Domain\Aevum\Fabric\Data\UserRules;
use App\Domain\Aevum\Fabric\Enums\FilterVerdict;
use App\Domain\Aevum\Fabric\Exceptions\CoreRibaExperienceException;
use App\Domain\Aevum\Fabric\Services\CurrencyExperienceRegistry;
use App\Domain\Aevum\Fabric\Services\EthicalFilter;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Finder\Finder;
use Tests\Support\LedgerFixtures;
use Tests\TestCase;

/**
 * NoLedgerAuthorityTest — A-§C.14 (No Authority Over Meridian's Ledger)
 * and A-§C.9 (Core Riba experience refusal).
 *
 * DOCUMENT 4.4: everything Aevum does is propose, surface, filter, or
 * refuse — never author a Meridian balance change. Triple-enforced:
 * compile-time (the Aevum module cannot even reference the ledger-write
 * vocabulary), DB (the aevum_app role holds no write privilege on
 * entries/transactions/accounts), and runtime (a full pass of every
 * Aevum surface produces zero ledger rows).
 */
final class NoLedgerAuthorityTest extends TestCase
{
    /**
     * Compile-time wall: no file in the Aevum module references the
     * ledger-write path. The same self-catching convention as the I7
     * and I9 walls — the tokens may not appear even in comments.
     */
    public function test_the_aevum_module_never_references_the_ledger_write_path(): void
    {
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

        $files = iterator_to_array(
            (new Finder())->files()->in(app_path('Domain/Aevum'))->name('*.php')
        );
        $this->assertNotEmpty($files);

        foreach ($files as $file) {
            $source = (string) file_get_contents($file->getPathname());
            foreach ($forbidden as $token) {
                $this->assertFalse(
                    str_contains($source, $token),
                    "A-§C.14 violated: {$file->getRelativePathname()} references the "
                    ."ledger-write path via \"{$token}\"."
                );
            }
        }
    }

    /**
     * DB wall: the aevum_app role independently lacks every write
     * privilege on the ledger tables — even a fully compromised Aevum
     * process running as its own role cannot author an entry.
     */
    public function test_the_aevum_db_role_cannot_write_the_ledger(): void
    {
        foreach (['entries', 'transactions', 'accounts'] as $table) {
            foreach (['INSERT', 'UPDATE', 'DELETE', 'TRUNCATE'] as $priv) {
                /** @var object{ok: bool} $allowed */
                $allowed = DB::selectOne(
                    'SELECT has_table_privilege(?, ?, ?) AS ok',
                    ['aevum_app', $table, $priv],
                );

                $this->assertFalse(
                    $allowed->ok,
                    "A-§C.14 violated: aevum_app holds {$priv} on {$table}."
                );
            }
        }

        // Its own four tables it CAN write — the wall is asymmetric,
        // not a lockout.
        foreach (['experience_specs', 'asset_labels', 'global_blocks', 'user_client_preferences'] as $table) {
            /** @var object{ok: bool} $allowed */
            $allowed = DB::selectOne(
                'SELECT has_table_privilege(?, ?, ?) AS ok',
                ['aevum_app', $table, 'INSERT'],
            );

            $this->assertTrue($allowed->ok, "aevum_app should INSERT on its own {$table}.");
        }
    }

    /**
     * Runtime proof: exercising the whole Aevum surface — registering
     * an experience, evaluating the filter across every verdict — adds
     * not one row to entries or transactions.
     */
    public function test_no_aevum_surface_produces_a_ledger_row(): void
    {
        $currency = LedgerFixtures::currency();
        DB::table('issuance_policies')->insert([
            'id' => strtolower((string) \Illuminate\Support\Str::ulid()),
            'currency_id' => $currency->id,
            'type' => 'povc',
            'params' => '{}',
            'base_kind' => 'contribution',
            'increase_kind' => 'none',
            'risk_bearing' => false,
            'value_creating' => true,
            'extracts_from_counterparty' => false,
        ]);

        $entriesBefore = (int) DB::table('entries')->count();
        $transactionsBefore = (int) DB::table('transactions')->count();

        $registry = app(CurrencyExperienceRegistry::class);
        $spec = $registry->register(new ExperienceRegistration(
            currencyId: $currency->id,
            presentation: ['title' => 'A contribution experience'],
        ));
        $this->assertTrue($spec->core_riba_checked);

        $filter = app(EthicalFilter::class);
        $rules = new UserRules(
            userRef: 'user-1',
            refuseCategories: ['weapons'],
            warnCategories: ['gambling'],
        );

        foreach ([
            new Asset(assetRef: 'asset-clean', labelCategories: []),
            new Asset(assetRef: 'asset-warned', labelCategories: ['gambling']),
            new Asset(assetRef: 'asset-refused', labelCategories: ['weapons']),
        ] as $asset) {
            $filter->evaluate($asset, $rules);
        }

        $this->assertSame(
            $entriesBefore,
            (int) DB::table('entries')->count(),
            'A-§C.14 violated: an Aevum surface produced a ledger entry.'
        );
        $this->assertSame(
            $transactionsBefore,
            (int) DB::table('transactions')->count(),
            'A-§C.14 violated: an Aevum surface produced a ledger transaction.'
        );
    }

    /**
     * A-§C.9: an experience whose underlying policy is Core Riba is
     * refused registration — defense-in-depth with Meridian's I11,
     * proven by injecting the forbidden policy shape directly (below
     * the I11 service guard, exactly the record-layer failure the
     * experience gate exists to survive). The DB CHECK also rejects
     * it, so we assert BOTH walls independently.
     */
    public function test_a_core_riba_experience_cannot_be_registered(): void
    {
        $currency = LedgerFixtures::currency();

        // Wall 1 (Meridian I11 DB CHECK): the forbidden shape cannot
        // even be stored in the system of record.
        try {
            DB::table('issuance_policies')->insert([
                'id' => strtolower((string) \Illuminate\Support\Str::ulid()),
                'currency_id' => $currency->id,
                'type' => 'reserve_1to1',
                'params' => '{}',
                'base_kind' => 'money',
                'increase_kind' => 'prefixed_guaranteed',
                'risk_bearing' => false,
                'value_creating' => false,
                'extracts_from_counterparty' => true,
            ]);
            $this->fail('I11 violated: a Core Riba policy row was stored.');
        } catch (\Illuminate\Database\QueryException $e) {
            $this->assertStringContainsString('issuance_policies_no_core_riba', $e->getMessage());
        }

        // Wall 2 (Aevum A-§C.9): even if the record layer had failed,
        // the registry refuses to SURFACE the shape. We verify against
        // the nearest storable neighbor mutated in memory — by driving
        // the registry's own predicate through a policy row we relax
        // one conjunct at a time; the full conjunction is never
        // registrable because it is never storable, so the registry
        // check is exercised via its dedicated unit surface.
        DB::table('issuance_policies')->insert([
            'id' => strtolower((string) \Illuminate\Support\Str::ulid()),
            'currency_id' => $currency->id,
            'type' => 'reserve_1to1',
            'params' => '{}',
            'base_kind' => 'money',
            'increase_kind' => 'prefixed_guaranteed',
            'risk_bearing' => true, // one conjunct relaxed: storable
            'value_creating' => false,
            'extracts_from_counterparty' => true,
        ]);

        // Storable neighbor registers fine (not squarely Core Riba)…
        $registry = app(CurrencyExperienceRegistry::class);
        $registry->register(new ExperienceRegistration(
            currencyId: $currency->id,
            presentation: ['title' => 'Risk-bearing reserve'],
        ));

        // …then flip the conjunct back via the constraint-free session
        // path PostgreSQL allows superusers? No — constraints bind all
        // roles. The registry predicate itself is proven by disabling
        // the CHECK inside a transaction we roll back.
        $currency2 = LedgerFixtures::currency();
        DB::beginTransaction();
        try {
            DB::statement('ALTER TABLE issuance_policies DROP CONSTRAINT issuance_policies_no_core_riba');
            DB::table('issuance_policies')->insert([
                'id' => strtolower((string) \Illuminate\Support\Str::ulid()),
                'currency_id' => $currency2->id,
                'type' => 'reserve_1to1',
                'params' => '{}',
                'base_kind' => 'money',
                'increase_kind' => 'prefixed_guaranteed',
                'risk_bearing' => false,
                'value_creating' => false,
                'extracts_from_counterparty' => true,
            ]);

            try {
                $registry->register(new ExperienceRegistration(
                    currencyId: $currency2->id,
                    presentation: ['title' => 'P·(1+r) product'],
                ));
                $this->fail('A-§C.9 violated: a Core Riba experience was registered.');
            } catch (CoreRibaExperienceException $e) {
                $this->assertStringContainsString('A-§C.9', $e->getMessage());
            }

            $this->assertSame(0, \App\Domain\Aevum\Fabric\Models\ExperienceSpec::query()
                ->where('currency_id', $currency2->id)->count());
        } finally {
            DB::rollBack();
        }
    }

    /**
     * The registry refuses to surface a currency with NO policy on
     * record at all — nothing to check against means no registration.
     */
    public function test_an_experience_without_an_issuance_policy_is_refused(): void
    {
        $currency = LedgerFixtures::currency();

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('no issuance policy');

        app(CurrencyExperienceRegistry::class)->register(new ExperienceRegistration(
            currencyId: $currency->id,
            presentation: ['title' => 'Unchecked experience'],
        ));
    }

    /**
     * The filter's whole output vocabulary is a verdict: reflection
     * proves EthicalFilter exposes no method whose return type could
     * carry a balance change, and FilterVerdict has exactly the three
     * negative-power values.
     */
    public function test_the_filter_has_no_debit_capability(): void
    {
        $reflection = new \ReflectionClass(EthicalFilter::class);
        $methods = $reflection->getMethods(\ReflectionMethod::IS_PUBLIC);

        $this->assertCount(1, $methods);
        $this->assertSame('evaluate', $methods[0]->getName());
        $this->assertSame(
            \App\Domain\Aevum\Fabric\Data\FilterResult::class,
            (string) $methods[0]->getReturnType(),
        );

        $this->assertSame(
            ['admit', 'warn', 'refuse'],
            array_map(static fn (FilterVerdict $v): string => $v->value, FilterVerdict::cases()),
        );
    }
}
