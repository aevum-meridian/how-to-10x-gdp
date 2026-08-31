<?php

/**
 * SPDX-License-Identifier: LicenseRef-AVL-2.0
 *
 * DEV-4.4 / A-§C.12 — the $PEG basket rebalancer: the canonical Tier-0
 * verified stabilizer (DOCUMENT 4.4: "e.g., the $PEG basket rebalancer
 * with per-epoch cap κ").
 *
 * Pure by construction: the adjustment is an arithmetic function of
 * the input weights alone — no clock, no I/O, no randomness, no state,
 * no learned component. Each asset's weight moves toward its target by
 * a proportional step, clamped to the per-epoch cap κ. Deterministic
 * ordering (ksort) makes the output independent of input array order.
 *
 * © Maher
 */

declare(strict_types=1);

namespace App\Domain\Aevum\Fabric\Tier0;

final class PegBasketRebalancer implements Tier0Rule
{
    /** κ — the hard per-epoch movement cap (2% of basket weight). */
    private const KAPPA = 0.02;

    public function ruleId(): string
    {
        return 'peg-basket-rebalancer-v1';
    }

    public function movementCap(): float
    {
        return self::KAPPA;
    }

    public function evaluate(Tier0Input $input): Tier0Adjustment
    {
        $adjustments = [];

        $targets = $input->targetWeights;
        ksort($targets);

        foreach ($targets as $assetRef => $target) {
            $current = $input->currentWeights[$assetRef] ?? 0.0;
            $gap = $target - $current;

            // Move half the gap per epoch, clamped to ±κ. Pure arithmetic.
            $step = $gap / 2.0;
            $clamped = max(-self::KAPPA, min(self::KAPPA, $step));

            $adjustments[$assetRef] = $clamped;
        }

        return new Tier0Adjustment(
            ruleId: $this->ruleId(),
            epoch: $input->epoch,
            relativeAdjustments: $adjustments,
        );
    }
}
