<?php

// SPDX-License-Identifier: LicenseRef-MVL-2.0

declare(strict_types=1);

namespace Tests\Invariants;

use App\Domain\Meridian\Ledger\Enums\ReversalReason;
use App\Domain\Meridian\Ledger\Enums\TransactionKind;
use App\Domain\Meridian\Ledger\Models\Entry;
use App\Domain\Meridian\Ledger\Models\LedgerTransaction;
use App\Domain\Meridian\Ledger\Services\LedgerService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Support\LedgerFixtures;
use Tests\TestCase;

/**
 * ImmutabilityTest — Invariant I5 (Append-Only Ledger).
 *
 * DOCUMENT 0.1: "No ledger row is ever updated or deleted. All corrections
 * are compensating (reversing) transactions."
 *
 * Triple enforcement checked here:
 *  - DB layer: ledger_forbid_mutation() trigger raises on any UPDATE/DELETE
 *    against `entries` and `transactions`, regardless of the column touched.
 *  - Service layer: LedgerService exposes no update/delete surface at all;
 *    corrections flow exclusively through reverse().
 *  - This property test.
 */
final class ImmutabilityTest extends TestCase
{
    private LedgerService $ledger;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ledger = app(LedgerService::class);
    }

    public function test_every_update_against_entries_and_transactions_is_rejected_by_the_database(): void
    {
        $c = LedgerFixtures::currency();
        $alice = LedgerFixtures::personalAccount($c);
        LedgerFixtures::mint($alice, 10_000, $this->ledger);

        $txn = LedgerTransaction::query()->firstOrFail();
        $entry = Entry::query()->firstOrFail();

        $updateAttempts = [
            ['transactions', 'kind', TransactionKind::Transfer->value, $txn->id],
            ['transactions', 'metadata', json_encode(['tampered' => true]), $txn->id],
            ['transactions', 'idempotency_key', 'rewritten-key', $txn->id],
            ['entries', 'amount', '999999', $entry->id],
            ['entries', 'balance_after', '0', $entry->id],
            ['entries', 'account_id', $alice->id, $entry->id],
        ];

        foreach ($updateAttempts as [$table, $column, $value, $id]) {
            try {
                DB::statement("UPDATE {$table} SET {$column} = ? WHERE id = ?", [$value, $id]);
                $this->fail("I5 violated: UPDATE {$table}.{$column} was permitted.");
            } catch (QueryException $e) {
                $this->assertStringContainsString(
                    'I5',
                    $e->getMessage(),
                    "UPDATE {$table}.{$column} must be rejected by ledger_forbid_mutation()."
                );
            }
        }
    }

    public function test_every_delete_against_entries_and_transactions_is_rejected_by_the_database(): void
    {
        $c = LedgerFixtures::currency();
        $alice = LedgerFixtures::personalAccount($c);
        LedgerFixtures::mint($alice, 10_000, $this->ledger);

        $txn = LedgerTransaction::query()->firstOrFail();
        $entry = Entry::query()->firstOrFail();

        foreach ([
            ['entries', $entry->id],
            ['transactions', $txn->id],
        ] as [$table, $id]) {
            try {
                DB::statement("DELETE FROM {$table} WHERE id = ?", [$id]);
                $this->fail("I5 violated: DELETE FROM {$table} was permitted.");
            } catch (QueryException $e) {
                $this->assertStringContainsString(
                    'I5',
                    $e->getMessage(),
                    "DELETE FROM {$table} must be rejected by ledger_forbid_mutation()."
                );
            }

            try {
                DB::statement("TRUNCATE {$table} CASCADE");
                // TRUNCATE bypasses row triggers; the privilege model (no
                // TRUNCATE grant for meridian_app) is the enforcement there.
                // Under the superuser test connection it may succeed, so we
                // do not fail here — the privilege assertion below covers it.
            } catch (QueryException) {
                // Also acceptable: some environments deny TRUNCATE outright.
            }
        }
    }

    public function test_the_application_role_holds_no_update_delete_or_truncate_privilege_on_ledger_tables(): void
    {
        foreach (['transactions', 'entries'] as $table) {
            foreach (['UPDATE', 'DELETE', 'TRUNCATE'] as $priv) {
                $allowed = DB::selectOne(
                    'SELECT has_table_privilege(?, ?, ?) AS ok',
                    ['meridian_app', $table, $priv]
                );
                $this->assertFalse(
                    (bool) $allowed->ok,
                    "I5 privilege model: meridian_app must not hold {$priv} on {$table}."
                );
            }
        }
    }

    public function test_corrections_are_compensating_transactions_that_leave_the_original_intact(): void
    {
        $c = LedgerFixtures::currency();
        $alice = LedgerFixtures::personalAccount($c);
        $bob = LedgerFixtures::personalAccount($c);
        LedgerFixtures::mint($alice, 50_000, $this->ledger);

        $transfer = LedgerFixtures::transfer($alice, $bob, 7_500, $this->ledger);

        $originalEntries = Entry::query()
            ->where('transaction_id', $transfer->id)
            ->orderBy('amount')
            ->get()
            ->map(fn (Entry $e): array => [
                'id' => $e->id,
                'account_id' => $e->account_id,
                'amount' => $e->amount,
                'balance_after' => $e->balance_after,
            ])
            ->all();

        // I10: the reversal debits Bob (the recipient of the original
        // credit), so it carries Bob's holder authorization. Without it,
        // the DB guard would reject the personal debit — proven below.
        try {
            $this->ledger->reverse($transfer, ReversalReason::ErrorCorrection);
            $this->fail('I10 violated: a reversal debiting a personal account without holder authorization was permitted.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('I6/I10', $e->getMessage());
        }

        $reversal = $this->ledger->reverse(
            $transfer,
            ReversalReason::ErrorCorrection,
            debitedHolderAuthorizationRef: 'holder-auth:'.$bob->id.':consents-to-reversal',
        );

        // 1. The reversal is a NEW transaction referencing the original.
        $this->assertNotSame($transfer->id, $reversal->id);
        $this->assertSame($transfer->id, $reversal->reverses_transaction_id);
        $this->assertSame(TransactionKind::Reversal, $reversal->kind);

        // 2. The original transaction and its entries are bit-for-bit intact.
        $after = Entry::query()
            ->where('transaction_id', $transfer->id)
            ->orderBy('amount')
            ->get()
            ->map(fn (Entry $e): array => [
                'id' => $e->id,
                'account_id' => $e->account_id,
                'amount' => $e->amount,
                'balance_after' => $e->balance_after,
            ])
            ->all();
        $this->assertSame($originalEntries, $after);

        // 3. Every reversal entry negates exactly one original entry and
        //    carries the reverses_entry_id back-link.
        $reversalEntries = Entry::query()
            ->where('transaction_id', $reversal->id)
            ->get();
        $this->assertCount(count($originalEntries), $reversalEntries);
        foreach ($reversalEntries as $re) {
            $this->assertNotNull($re->reverses_entry_id, 'Reversal entries must link the entry they compensate.');
            $orig = Entry::query()->findOrFail($re->reverses_entry_id);
            $this->assertSame(-1 * (int) $orig->amount, (int) $re->amount);
            $this->assertSame($orig->account_id, $re->account_id);
        }

        // 4. Net effect restored: balances are back to pre-transfer state.
        $this->assertSame('500.00', (string) $this->ledger->balance($alice->refresh())->getAmount());
        $this->assertSame('0.00', (string) $this->ledger->balance($bob->refresh())->getAmount());
        $this->assertTrue($this->ledger->reconcile($alice)->consistent);
        $this->assertTrue($this->ledger->reconcile($bob)->consistent);
    }

    public function test_a_reversal_of_a_reversal_is_the_only_way_to_reinstate_and_also_appends(): void
    {
        $c = LedgerFixtures::currency();
        $alice = LedgerFixtures::personalAccount($c);
        $bob = LedgerFixtures::personalAccount($c);
        LedgerFixtures::mint($alice, 50_000, $this->ledger);

        $transfer = LedgerFixtures::transfer($alice, $bob, 7_500, $this->ledger);
        $reversal = $this->ledger->reverse(
            $transfer,
            ReversalReason::ErrorCorrection,
            debitedHolderAuthorizationRef: 'holder-auth:'.$bob->id.':consents-to-reversal',
        );
        // Reinstating re-debits Alice, so Alice's consent is required.
        $reinstate = $this->ledger->reverse(
            $reversal,
            ReversalReason::OperationalReversal,
            debitedHolderAuthorizationRef: 'holder-auth:'.$alice->id.':consents-to-reinstate',
        );

        $this->assertSame(3 + 1, LedgerTransaction::query()->count()); // mint + transfer + reversal + reinstate
        $this->assertSame('425.00', (string) $this->ledger->balance($alice->refresh())->getAmount());
        $this->assertSame('75.00', (string) $this->ledger->balance($bob->refresh())->getAmount());
        $this->assertTrue($this->ledger->reconcile($alice)->consistent);
        $this->assertTrue($this->ledger->reconcile($bob)->consistent);
    }
}
