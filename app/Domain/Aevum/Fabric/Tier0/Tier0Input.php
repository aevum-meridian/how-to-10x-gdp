<?php

/**
 * SPDX-License-Identifier: LicenseRef-AVL-2.0
 *
 * DEV-4.4 / A-§C.12 — the complete, explicit input to a Tier-0 rule.
 * Everything a rule may consider is HERE — a rule that needs a clock
 * receives the epoch as data; a rule that needs prices receives them
 * as data. This is what makes purity checkable: the input is the
 * whole world. © Maher
 */

declare(strict_types=1);

namespace App\Domain\Aevum\Fabric\Tier0;

use Spatie\LaravelData\Data;

final class Tier0Input extends Data
{
    /**
     * @param array<string, float> $observedPrices  asset ref => observed price
     * @param array<string, float> $targetWeights   asset ref => target basket weight
     * @param array<string, float> $currentWeights  asset ref => current basket weight
     */
    public function __construct(
        public readonly int $epoch,
        public readonly array $observedPrices,
        public readonly array $targetWeights,
        public readonly array $currentWeights,
    ) {
    }
}
