<?php

/**
 * SPDX-License-Identifier: LicenseRef-MVL-2.0 AND LicenseRef-AVL-2.0
 *
 * DEV-6.x / DOCUMENT 6.2 §2 — an explainable, appealable Sybil score.
 * Carries its reasons with it; a score without reasons cannot be
 * constructed. © Maher
 */

declare(strict_types=1);

namespace App\Domain\Identity\Ladder\Data;

use Spatie\LaravelData\Data;

final class SybilScore extends Data
{
    /** @param list<string> $reasons must be non-empty — enforced in the constructor */
    public function __construct(
        public readonly string $identityId,
        public readonly float $score,
        public readonly array $reasons,
        public readonly bool $appealable,
    ) {
        if ($score < 0.0 || $score > 1.0) {
            throw new \InvalidArgumentException('A Sybil score is a probability-like value in [0, 1].');
        }

        if ($reasons === []) {
            throw new \InvalidArgumentException(
                'An unexplained score is a black-box exclusion; every score carries its reasons.'
            );
        }
    }
}
