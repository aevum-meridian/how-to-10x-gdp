<?php

/**
 * SPDX-License-Identifier: LicenseRef-MVL-2.0
 *
 * BalanceReconcileTest — the canonical I2 test (DOCUMENT 0.1 §XREF,
 * DOCUMENT 4.1, DOCUMENT 9.2 §1).
 *
 * Property: after any sequence of posted transactions, recomputing every
 * balance from the full entry history equals the stored balance, and
 * balance_after is consistent at every step. Discrepancies alert, never
 * auto-correct.
 *
 * © Maher
 */

declare(strict_types=1);

use App\Domain\Meridian\Ledger\Models\Account;
use App\Domain\Meridian\Ledger\Models\Entry;
use App\Domain\Meridian\Ledger\Services\LedgerService;
use Illuminate\Support\Facades\DB;
use Tests\Support\LedgerFixtures;

describe('BalanceReconcileTest (I2)', function (): void {
    test('property: after any random transaction sequence, recomputed balances equal stored balances', function (): void {
        $ledger = new LedgerService();
        $currency = LedgerFixtures::currency();

        $accounts = [];
        for ($i = 0; $i < 5; $i++) {
            $accounts[] = LedgerFixtures::personalAccount($currency);
        }

        foreach ($accounts as $account) {
            LedgerFixtures::mint($account, random_int(10_000, 50_000), $ledger);
        }

        // Random sequence of transfers.
        for ($i = 0; $i < 40; $i++) {
            $from = $accounts[random_int(0, 4)];
            $to = $accounts[random_int(0, 4)];

            if ($from->id === $to->id) {
                continue;
            }

            $from->refresh();
            $available = $from->balance_minor;

            if ($available < 1) {
                continue;
            }

            LedgerFixtures::transfer($from, $to, random_int(1, min(1_000, $available)), $ledger);
        }

        foreach ($accounts as $account) {
            $result = $ledger->reconcile($account);
            expect($result->consistent)->toBeTrue()
                ->and($result->recomputedBalanceMinor)->toBe($result->storedBalanceMinor);
        }

        // The must-stay-empty discrepancy table stayed empty.
        expect(DB::table('ledger_discrepancies')->count())->toBe(0);
    });

    test('balance_after is computed at insert and matches the running history for every entry', function (): void {
        $ledger = new LedgerService();
        $currency = LedgerFixtures::currency();
        $a = LedgerFixtures::personalAccount($currency);
        $b = LedgerFixtures::personalAccount($currency);
        LedgerFixtures::mint($a, 100_000, $ledger);

        for ($i = 0; $i < 10; $i++) {
            LedgerFixtures::transfer($a, $b, random_int(1, 100), $ledger);
        }

        foreach ([$a, $b] as $account) {
            $running = 0;

            foreach (Entry::query()->where('account_id', $account->id)->orderBy('id')->get() as $entry) {
                $running += $entry->amount;
                expect($entry->balance_after)->toBe($running);
            }

            $account->refresh();
            expect($account->balance_minor)->toBe($running);
        }
    });

    test('a discrepancy is recorded and alerted, never auto-corrected', function (): void {
        $ledger = new LedgerService();
        $currency = LedgerFixtures::currency();
        $a = LedgerFixtures::personalAccount($currency);
        LedgerFixtures::mint($a, 5_000, $ledger);

        // Corrupt the cached aggregate directly (accounts.balance_minor is
        // a mutable cache, not the ledger; the ledger itself is immutable).
        DB::table('accounts')->where('id', $a->id)->update(['balance_minor' => 9_999]);

        $result = $ledger->reconcile($a->refresh());

        expect($result->consistent)->toBeFalse();

        // Alerted: a discrepancy row exists.
        $row = DB::table('ledger_discrepancies')->where('account_id', $a->id)->first();
        expect($row)->not->toBeNull()
            ->and((int) $row->expected_minor)->toBe(5_000)
            ->and((int) $row->actual_minor)->toBe(9_999);

        // Never auto-corrected: the stored (corrupted) aggregate is untouched
        // by reconcile(); the correction is a human act through the normal
        // ledger path.
        expect(Account::query()->findOrFail($a->id)->balance_minor)->toBe(9_999);
    });
});
