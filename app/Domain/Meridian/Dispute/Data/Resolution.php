<?php

/**
 * SPDX-License-Identifier: LicenseRef-MVL-2.0
 *
 * DEV-4.3 — the outcome of arbitrate(): binds the closed case to its
 * specific mint. This is the input to applyArbitrationReversal(), which
 * re-verifies every conjunct of the I6-revised predicate itself — a
 * Resolution is a claim, not an authorization. © Maher
 */

declare(strict_types=1);

namespace App\Domain\Meridian\Dispute\Data;

use Spatie\LaravelData\Data;

final class Resolution extends Data
{
    /**
     * @param non-empty-string $disputeId The closed arbitration case.
     * @param non-empty-string $mintTransactionId The specific fraudulent
     *     mint the case ruled on.
     * @param bool $fraudProven
     * @param string|null $fraudulentPartyAccountId
     */
    public function __construct(
        public readonly string $disputeId,
        public readonly string $mintTransactionId,
        public readonly bool $fraudProven,
        public readonly ?string $fraudulentPartyAccountId,
    ) {
    }
}
