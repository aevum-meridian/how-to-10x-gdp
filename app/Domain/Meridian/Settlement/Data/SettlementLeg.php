<?php

/**
 * SPDX-License-Identifier: LicenseRef-MVL-2.0
 *
 * DEV-7.x — one leg of a settlement: a balanced pair of movements in
 * one currency. Every debited personal account must present its
 * holder's authorization (I10) — the coordinator verifies this BEFORE
 * any commit begins. © Maher
 */

declare(strict_types=1);

namespace App\Domain\Meridian\Settlement\Data;

use Spatie\LaravelData\Data;

final class SettlementLeg extends Data
{
    public function __construct(
        public readonly string $fromAccountId,
        public readonly string $toAccountId,
        public readonly string $currencyId,
        public readonly int $amountMinor,
        public readonly ?string $holderAuthorizationRef = null,
    ) {
        if ($amountMinor <= 0) {
            throw new \InvalidArgumentException('A settlement leg must move a positive amount.');
        }
    }
}
