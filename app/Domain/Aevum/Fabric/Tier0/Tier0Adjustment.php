<?php

/**
 * SPDX-License-Identifier: LicenseRef-AVL-2.0
 *
 * DEV-4.4 / A-§C.12 — the output of a Tier-0 rule: per-asset relative
 * weight adjustments. This is a PROPOSAL surface only — nothing in
 * Aevum executes it against a ledger (A-§C.14); value movement, if
 * any, goes through the signed cross-system event contract for
 * Meridian to validate. © Maher
 */

declare(strict_types=1);

namespace App\Domain\Aevum\Fabric\Tier0;

use Spatie\LaravelData\Data;

final class Tier0Adjustment extends Data
{
    /** @param array<string, float> $relativeAdjustments asset ref => relative weight delta */
    public function __construct(
        public readonly string $ruleId,
        public readonly int $epoch,
        public readonly array $relativeAdjustments,
    ) {
    }

    /** The largest absolute adjustment — compared against the rule's cap. */
    public function maxAbsoluteAdjustment(): float
    {
        $max = 0.0;

        foreach ($this->relativeAdjustments as $delta) {
            $max = max($max, abs($delta));
        }

        return $max;
    }
}
