<?php

/**
 * SPDX-License-Identifier: LicenseRef-MVL-2.0
 *
 * DOCUMENT 4.1 — an EntryDraft carries account, currency, and a signed
 * Money amount in bigint minor units (never float — DEV-0).
 *
 * © Maher
 */

declare(strict_types=1);

namespace App\Domain\Meridian\Ledger\Data;

use Spatie\LaravelData\Data;

final class EntryDraft extends Data
{
    public function __construct(
        public readonly string $accountId,
        public readonly string $currencyId,
        public readonly int $amountMinor,
        public readonly ?string $holderAuthorizationRef = null,
    ) {
        if ($amountMinor === 0) {
            throw new \InvalidArgumentException('An entry amount of zero is meaningless and refused.');
        }
    }
}
