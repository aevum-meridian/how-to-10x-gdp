<?php

/**
 * SPDX-License-Identifier: LicenseRef-AVL-2.0
 *
 * DEV-4.4 — the ethical filter's full result: the verdict plus WHY
 * (which rule and which labels produced it), because a filter whose
 * reasoning is invisible is a filter whose sovereignty is fictional.
 * © Maher
 */

declare(strict_types=1);

namespace App\Domain\Aevum\Fabric\Data;

use App\Domain\Aevum\Fabric\Enums\FilterVerdict;
use Spatie\LaravelData\Data;

final class FilterResult extends Data
{
    /** @param list<string> $matchedCategories */
    public function __construct(
        public readonly FilterVerdict $verdict,
        public readonly string $reason,
        public readonly array $matchedCategories,
        public readonly bool $userOverridable,
    ) {
    }
}
