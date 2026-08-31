<?php

/**
 * SPDX-License-Identifier: LicenseRef-MVL-2.0
 * DEV-4.2 — an attested custody deposit backing a reserve mint. © Maher
 */

declare(strict_types=1);

namespace App\Domain\Meridian\Issuance\Data;

use Spatie\LaravelData\Data;

final class ReserveDeposit extends Data
{
    /**
     * @param non-empty-string $recipientAccountId
     * @param positive-int $amountMinor
     * @param int<0, max> $attestedReserveMinor Total custody attested by
     *     the current reserve attestation (DEV-8.3 supplies this; here it
     *     is passed by the caller from the latest attestation record).
     * @param non-empty-string $custodyAttestationRef
     * @param non-empty-string $idempotencyKey
     */
    public function __construct(
        public readonly string $recipientAccountId,
        public readonly int $amountMinor,
        public readonly int $attestedReserveMinor,
        public readonly string $custodyAttestationRef,
        public readonly string $idempotencyKey,
    ) {
    }
}
