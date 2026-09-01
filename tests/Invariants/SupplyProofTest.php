<?php

/**
 * SPDX-License-Identifier: LicenseRef-MVL-2.0
 *
 * SupplyProofTest — the canonical I3 test (DOCUMENT 0.1 §XREF,
 * DOCUMENT 4.1, DOCUMENT 9.2 §1).
 *
 * Property: for every currency, the sum of all user account balances
 * equals net issuance (mintedTotal − burnedTotal), across random
 * mint/burn/transfer sequences. Only mint/burn change net issuance, each
 * a balanced transaction against ISSUANCE/BURN.
 *
 * © Maher
 */

declare(strict_types=1);

use App\Domain\Meridian\Ledger\Data\EntryDraft;
use App\Domain\Meridian\Ledger\Data\TransactionDraft;
use App\Domain\Meridian\Ledger\Enums\SystemAccountRole;
use App\Domain\Meridian\Ledger\Enums\TransactionKind;
use App\Domain\Meridian\Ledger\Services\LedgerService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\LedgerFixtures;

describe('SupplyProofTest (I3)', function (): void {
    test('property: user-balance-sum equals net issuance across random mint/burn/transfer sequences', function (): void {
        $ledger = new LedgerService();
        $currency = LedgerFixtures::currency();
        $issuance = LedgerFixtures::systemAccount($currency, SystemAccountRole::Issuance);
        $burn = LedgerFixtures::systemAccount($currency, SystemAccountRole::Burn);

        $accounts = [];
        for ($i = 0; $i < 3; $i++) {
            $accounts[] = LedgerFixtures::personalAccount($currency);
        }

        $minted = 0;
        $burned = 0;

        for ($i = 0; $i < 60; $i++) {
            $op = random_int(0, 2);
            $account = $accounts[random_int(0, 2)];
            $account->refresh();

            if ($op === 0) {
                $amount = random_int(1, 10_000);
                LedgerFixtures::mint($account, $amount, $ledger);
                $minted += $amount;
            } elseif ($op === 1 && $account->balance_minor > 0) {
                // Burn: holder-authorized redemption into the BURN account.
                $amount = random_int(1, $account->balance_minor);
                $ledger->post(new TransactionDraft(
                    kind: TransactionKind::Burn,
                    entries: [
                        new EntryDraft($account->id, $currency->id, -$amount, holderAuthorizationRef: 'auth:'.Str::ulid()),
                        new EntryDraft($burn->id, $currency->id, $amount),
                    ],
                    idempotencyKey: 'burn:'.Str::ulid(),
                ));
                $burned += $amount;
            } elseif ($op === 2 && $account->balance_minor > 0) {
                $to = $accounts[random_int(0, 2)];

                if ($to->id !== $account->id) {
                    LedgerFixtures::transfer($account, $to, random_int(1, $account->balance_minor), $ledger);
                }
            }
        }

        // User-balance sum equals net issuance.
        $userSum = (int) DB::table('accounts')
            ->where('currency_id', $currency->id)
            ->where('owner_type', 'person')
            ->sum('balance_minor');

        expect($userSum)->toBe($minted - $burned);

        // The issuance ledger's own record agrees: ISSUANCE holds −minted,
        // BURN holds +burned, and the whole currency conserves to zero.
        expect((int) $issuance->refresh()->balance_minor)->toBe(-$minted)
            ->and((int) $burn->refresh()->balance_minor)->toBe($burned);

        $total = (int) DB::table('accounts')->where('currency_id', $currency->id)->sum('balance_minor');
        expect($total)->toBe(0);

        // The daily materialized proof passes and writes no discrepancy.
        expect($ledger->proveSupplyIntegrity())->toBe([])
            ->and(DB::table('ledger_discrepancies')->where('check_kind', 'supply_proof')->count())->toBe(0);
    });

    test('the daily materialized check writes a discrepancy row (alert, never auto-correct) on phantom supply', function (): void {
        $ledger = new LedgerService();
        $currency = LedgerFixtures::currency();
        $a = LedgerFixtures::personalAccount($currency);
        LedgerFixtures::mint($a, 1_000, $ledger);

        // Simulate phantom supply by corrupting the cached aggregate.
        DB::table('accounts')->where('id', $a->id)->update(['balance_minor' => 2_000]);

        $failures = $ledger->proveSupplyIntegrity();

        expect($failures)->toContain($currency->id);

        $row = DB::table('ledger_discrepancies')
            ->where('check_kind', 'supply_proof')
            ->where('currency_id', $currency->id)
            ->first();

        expect($row)->not->toBeNull()
            ->and((int) $row->actual_minor)->toBe(1_000); // the phantom excess

        // Never auto-corrected.
        expect((int) DB::table('accounts')->where('id', $a->id)->value('balance_minor'))->toBe(2_000);
    });
});
