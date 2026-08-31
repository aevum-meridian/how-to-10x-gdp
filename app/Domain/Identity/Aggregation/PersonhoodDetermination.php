<?php

/**
 * SPDX-License-Identifier: LicenseRef-MVL-2.0 AND LicenseRef-AVL-2.0
 *
 * The explainable, appealable personhood determination — DOCUMENT 6.1 §4.
 * Every denial produces a human-readable explanation and a human appeal
 * with a bound turnaround (EU AI Act high-risk decision discipline).
 *
 * © Maher
 */

declare(strict_types=1);

namespace App\Domain\Identity\Aggregation;

use App\Domain\Identity\Enums\AssuranceRung;
use Spatie\LaravelData\Data;

final class PersonhoodDetermination extends Data
{
    /** @param list<string> $verifiedProviderIds */
    public function __construct(
        public readonly AssuranceRung $effectiveRung,
        public readonly string $aggregationVersion,
        public readonly array $verifiedProviderIds,
        public readonly string $explanation,
        public readonly bool $appealable,
    ) {
    }
}
