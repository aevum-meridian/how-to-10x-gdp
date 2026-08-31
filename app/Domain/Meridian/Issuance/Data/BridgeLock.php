<?php

/**
 * SPDX-License-Identifier: LicenseRef-MVL-2.0
 * DEV-4.2 — a confirmed source-chain lock backing a 1:1 bridged
 * representation. No confirmed lock, no mint. © Maher
 */

declare(strict_types=1);

namespace App\Domain\Meridian\Issuance\Data;

use Spatie\LaravelData\Data;

final class BridgeLock extends Data
{
    /**
     * @param non-empty-string $recipientAccountId
     * @param positive-int $amountMinor
     * @param non-empty-string $sourceChain
     * @param non-empty-string $sourceLockRef
     * @param non-empty-string $idempotencyKey
     */
    public function __construct(
        public readonly string $recipientAccountId,
        public readonly int $amountMinor,
        public readonly string $sourceChain,
        public readonly string $sourceLockRef,
        public readonly bool $lockConfirmed,
        public readonly string $idempotencyKey,
    ) {
    }
}
