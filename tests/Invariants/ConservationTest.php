<?php

/**
 * SPDX-License-Identifier: LicenseRef-MVL-2.0
 *
 * ConservationTest — the canonical I1 test (DOCUMENT 0.1 §XREF,
 * DOCUMENT 4.1, DOCUMENT 9.2 §1).
 *
 * Property: every posted transaction's entries sum to exactly zero per
 * currency. Random balanced and unbalanced drafts are generated; every
 * unbalanced one is rejected at BOTH the service layer and the database
 * layer and never persists. Includes the mandated concurrency stress:
 * many parallel transfers against overlapping accounts never break I1–I3.
 *
 * © Maher
 */

declare(strict_types=1);

use App\Domain\Meridian\Ledger\Data\EntryDraft;
use App\Domain\Meridian\Ledger\Data\TransactionDraft;
use App\Domain\Meridian\Ledger\Enums\TransactionKind;
use App\Domain\Meridian\Ledger\Exceptions\UnbalancedTransactionException;
use App\Domain\Meridian\Ledger\Models\Entry;
use App\Domain\Meridian\Ledger\Models\LedgerTransaction;
use App\Domain\Meridian\Ledger\Services\LedgerService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\LedgerFixtures;

describe('ConservationTest (I1)', function (): void {
    test('property: random balanced drafts post and their entries sum to zero per currency', function (): void {
        $ledger = new LedgerService();
        $currency = LedgerFixtures::currency();
        $a = LedgerFixtures::personalAccount($currency);
        $b = LedgerFixtures::personalAccount($currency);
        LedgerFixtures::mint($a, 1_000_000, $ledger);

        for ($i = 0; $i < 25; $i++) {
            $amount = random_int(1, 1_000);
            $transaction = LedgerFixtures::transfer($a, $b, $amount, $ledger);

            $sum = (int) Entry::query()
                ->where('transaction_id', $transaction->id)
                ->sum('amount');

            expect($sum)->toBe(0);
        }
    });

    test('property: every unbalanced draft is rejected at the service layer and never persists', function (): void {
        $ledger = new LedgerService();
        $currency = LedgerFixtures::currency();
        $a = LedgerFixtures::personalAccount($currency);
        $b = LedgerFixtures::personalAccount($currency);
        LedgerFixtures::mint($a, 1_000_000, $ledger);

        $countBefore = LedgerTransaction::query()->count();

        for ($i = 0; $i < 25; $i++) {
            $debit = random_int(1, 1_000);
            // Skew the credit so the draft never balances.
            $credit = $debit + random_int(1, 500);

            $draft = new TransactionDraft(
                kind: TransactionKind::Transfer,
                entries: [
                    new EntryDraft($a->id, $currency->id, -$debit, holderAuthorizationRef: 'auth:x'),
                    new EntryDraft($b->id, $currency->id, $credit),
                ],
                idempotencyKey: 'unbalanced:'.Str::ulid(),
            );

            expect(static fn (): LedgerTransaction => $ledger->post($draft))
                ->toThrow(UnbalancedTransactionException::class);
        }

        expect(LedgerTransaction::query()->count())->toBe($countBefore);
    });

    test('the database rejects an unbalanced transaction at commit even if the service is bypassed (deferred I1 trigger)', function (): void {
        $currency = LedgerFixtures::currency();
        $a = LedgerFixtures::personalAccount($currency);
        LedgerFixtures::mint($a, 10_000);

        $thrown = null;

        try {
            DB::transaction(function () use ($a, $currency): void {
                $txnId = (string) Str::ulid();
                DB::table('transactions')->insert([
                    'id' => $txnId,
                    'kind' => 'transfer',
                    'idempotency_key' => 'bypass:'.Str::ulid(),
                    'metadata' => '{}',
                ]);
                // A single unbalanced entry, inserted directly (malicious
                // implementer bypassing LedgerService).
                DB::table('entries')->insert([
                    'transaction_id' => $txnId,
                    'account_id' => $a->id,
                    'currency_id' => $currency->id,
                    'amount' => -500,
                    'balance_after' => 0,
                    'holder_authorization_ref' => 'auth:bypass',
                ]);
            });
        } catch (Throwable $e) {
            $thrown = $e;
        }

        expect($thrown)->not->toBeNull()
            ->and($thrown?->getMessage())->toContain('I1');

        // Nothing persisted.
        expect(DB::table('entries')->where('holder_authorization_ref', 'auth:bypass')->count())->toBe(0);
    });

    test('concurrency stress: parallel transfers against overlapping accounts never break conservation or supply', function (): void {
        $ledger = new LedgerService();
        $currency = LedgerFixtures::currency();
        $accounts = [];

        for ($i = 0; $i < 4; $i++) {
            $accounts[] = LedgerFixtures::personalAccount($currency);
        }

        foreach ($accounts as $account) {
            LedgerFixtures::mint($account, 100_000, $ledger);
        }

        $accountIds = array_map(static fn ($a): string => $a->id, $accounts);

        // Fork 8 workers, each performing 15 random transfers between
        // overlapping accounts through fresh PDO connections.
        DB::disconnect();
        $workers = [];

        for ($w = 0; $w < 8; $w++) {
            $pid = pcntl_fork();

            if ($pid === 0) {
                try {
                    DB::reconnect();
                    $childLedger = new LedgerService();

                    for ($i = 0; $i < 15; $i++) {
                        $fromIdx = random_int(0, 3);
                        $toIdx = ($fromIdx + random_int(1, 3)) % 4;

                        try {
                            $from = App\Domain\Meridian\Ledger\Models\Account::query()->findOrFail($accountIds[$fromIdx]);
                            $to = App\Domain\Meridian\Ledger\Models\Account::query()->findOrFail($accountIds[$toIdx]);
                            LedgerFixtures::transfer($from, $to, random_int(1, 100), $childLedger);
                        } catch (Illuminate\Database\QueryException) {
                            // Serialization conflicts/deadlocks may surface as
                            // rejected transactions — never as partial posts.
                        }
                    }

                    exit(0);
                } catch (Throwable) {
                    exit(1);
                }
            }

            $workers[] = $pid;
        }

        foreach ($workers as $pid) {
            pcntl_waitpid($pid, $status);
        }

        DB::reconnect();

        // I1: every posted transaction still sums to zero per currency.
        $unbalanced = DB::table('entries')
            ->select('transaction_id')
            ->where('currency_id', $currency->id)
            ->groupBy('transaction_id')
            ->havingRaw('SUM(amount) <> 0')
            ->count();
        expect($unbalanced)->toBe(0);

        // I2: every stored balance equals its recomputed history.
        foreach ($accountIds as $id) {
            $account = App\Domain\Meridian\Ledger\Models\Account::query()->findOrFail($id);
            $result = $ledger->reconcile($account);
            expect($result->consistent)->toBeTrue();
        }

        // I3: the currency's total (user + system) is conserved at zero.
        $total = (int) DB::table('accounts')->where('currency_id', $currency->id)->sum('balance_minor');
        expect($total)->toBe(0);
    });
});
