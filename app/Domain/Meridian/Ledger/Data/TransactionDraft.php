<?php

/**
 * SPDX-License-Identifier: LicenseRef-MVL-2.0
 *
 * DOCUMENT 4.1 — a TransactionDraft is a value object carrying a set of
 * EntryDrafts, an idempotency key, and metadata. The LedgerService rejects
 * any draft whose entries do not sum to zero per currency before any write
 * (I1 service guard).
 *
 * © Maher
 */

declare(strict_types=1);

namespace App\Domain\Meridian\Ledger\Data;

use App\Domain\Meridian\Ledger\Enums\TransactionKind;
use Spatie\LaravelData\Data;

final class TransactionDraft extends Data
{
    /**
     * @param list<EntryDraft> $entries
     * @param array<string, string|int|bool> $metadata
     */
    public function __construct(
        public readonly TransactionKind $kind,
        public readonly array $entries,
        public readonly string $idempotencyKey,
        public readonly array $metadata = [],
        public readonly ?string $reversesTransactionId = null,
        public readonly ?string $reversesMintTransactionId = null,
        public readonly ?string $arbitrationCaseId = null,
    ) {
    }

    /**
     * Per-currency sums — the I1 pre-write check.
     *
     * @return array<string, int>
     */
    public function perCurrencySums(): array
    {
        $sums = [];

        foreach ($this->entries as $entry) {
            $sums[$entry->currencyId] = ($sums[$entry->currencyId] ?? 0) + $entry->amountMinor;
        }

        return $sums;
    }

    public function isBalanced(): bool
    {
        foreach ($this->perCurrencySums() as $sum) {
            if ($sum !== 0) {
                return false;
            }
        }

        return true;
    }
}
