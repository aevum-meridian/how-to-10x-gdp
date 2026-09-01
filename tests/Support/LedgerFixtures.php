<?php

/**
 * SPDX-License-Identifier: LicenseRef-MVL-2.0
 * Shared fixtures for the invariant suite (DOCUMENT 9.2). © Maher
 */

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Meridian\Ledger\Data\EntryDraft;
use App\Domain\Meridian\Ledger\Data\TransactionDraft;
use App\Domain\Meridian\Ledger\Enums\AccountType;
use App\Domain\Meridian\Ledger\Enums\CurrencyFamily;
use App\Domain\Meridian\Ledger\Enums\SystemAccountRole;
use App\Domain\Meridian\Ledger\Enums\TransactionKind;
use App\Domain\Meridian\Ledger\Models\Account;
use App\Domain\Meridian\Ledger\Models\Currency;
use App\Domain\Meridian\Ledger\Models\LedgerTransaction;
use App\Domain\Meridian\Ledger\Services\LedgerService;
use Illuminate\Support\Str;

final class LedgerFixtures
{
    public static function currency(
        CurrencyFamily $family = CurrencyFamily::Contribution,
        ?string $code = null,
    ): Currency {
        $currency = new Currency([
            'code' => $code ?? 'TST'.strtoupper(Str::random(8)),
            'name' => 'Test Currency',
            'family' => $family,
            'decimals' => 2,
        ]);
        $currency->save();

        foreach (SystemAccountRole::cases() as $role) {
            $account = new Account([
                'owner_type' => 'system',
                'currency_id' => $currency->id,
                'type' => AccountType::System,
                'system_role' => $role,
            ]);
            $account->save();
        }

        return $currency;
    }

    public static function systemAccount(Currency $currency, SystemAccountRole $role): Account
    {
        return Account::query()
            ->where('currency_id', $currency->id)
            ->where('system_role', $role->value)
            ->firstOrFail();
    }

    public static function personalAccount(Currency $currency, ?string $ownerId = null): Account
    {
        $account = new Account([
            'owner_id' => $ownerId ?? (string) Str::ulid(),
            'owner_type' => 'person',
            'currency_id' => $currency->id,
            'type' => AccountType::Asset,
        ]);
        $account->save();

        return $account;
    }

    /**
     * Mint into a personal account through the balanced ISSUANCE path
     * (I3: only mint/burn change net issuance, always balanced).
     */
    public static function mint(Account $to, int $amountMinor, ?LedgerService $ledger = null): LedgerTransaction
    {
        $ledger ??= new LedgerService();
        $issuance = self::systemAccount($to->currency()->firstOrFail(), SystemAccountRole::Issuance);

        return $ledger->post(new TransactionDraft(
            kind: TransactionKind::Mint,
            entries: [
                new EntryDraft($issuance->id, $to->currency_id, -$amountMinor),
                new EntryDraft($to->id, $to->currency_id, $amountMinor),
            ],
            idempotencyKey: 'mint:'.Str::ulid(),
        ));
    }

    /**
     * A holder-authorized transfer between two accounts.
     */
    /** @param array<string, mixed> $metadata */
    public static function transfer(Account $from, Account $to, int $amountMinor, ?LedgerService $ledger = null, array $metadata = []): LedgerTransaction
    {
        $ledger ??= new LedgerService();

        return $ledger->post(new TransactionDraft(
            kind: TransactionKind::Transfer,
            entries: [
                new EntryDraft($from->id, $from->currency_id, -$amountMinor, holderAuthorizationRef: 'auth:'.Str::ulid()),
                new EntryDraft($to->id, $to->currency_id, $amountMinor),
            ],
            idempotencyKey: 'transfer:'.Str::ulid(),
            metadata: $metadata,
        ));
    }
}
