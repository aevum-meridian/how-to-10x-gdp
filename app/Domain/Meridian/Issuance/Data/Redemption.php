<?php

/**
 * SPDX-License-Identifier: LicenseRef-MVL-2.0
 * DEV-4.2 — a holder-authorized redemption burning reserve-backed units.
 * The debit side is the holder's own account, so I10 requires the
 * holder's authorization reference. © Maher
 */

declare(strict_types=1);

namespace App\Domain\Meridian\Issuance\Data;

use Spatie\LaravelData\Data;

final class Redemption extends Data
{
    /**
     * @param non-empty-string $holderAccountId
     * @param positive-int $amountMinor
     * @param non-empty-string $holderAuthorizationRef
     * @param non-empty-string $idempotencyKey
     */
    public function __construct(
        public readonly string $holderAccountId,
        public readonly int $amountMinor,
        public readonly string $holderAuthorizationRef,
        public readonly string $idempotencyKey,
    ) {
    }
}
