<?php

/**
 * SPDX-License-Identifier: LicenseRef-MVL-2.0
 *
 * DEV-4.3 — the public human arbitration tier's ruling: made in the
 * open, with a signed decision receipt (the GAS decision-receipt
 * pattern — a human-readable record of WHY the outcome happened). © Maher
 */

declare(strict_types=1);

namespace App\Domain\Meridian\Dispute\Data;

use Spatie\LaravelData\Data;

final class ArbitrationRuling extends Data
{
    /**
     * @param bool $fraudProven Whether the mint was ruled fraudulent.
     * @param string|null $fraudulentPartyAccountId The account of the
     *     PROVEN fraudulent party, or null when the recipient is
     *     innocent (clawback then targets bonds only — I6).
     * @param non-empty-string $decisionReceipt Human-readable rationale.
     * @param non-empty-string $arbitratorSignature Signature over the
     *     receipt by the public arbitration tier.
     */
    public function __construct(
        public readonly bool $fraudProven,
        public readonly ?string $fraudulentPartyAccountId,
        public readonly string $decisionReceipt,
        public readonly string $arbitratorSignature,
    ) {
    }
}
