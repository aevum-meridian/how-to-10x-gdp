<?php

/**
 * SPDX-License-Identifier: LicenseRef-AVL-2.0
 *
 * DEV-4.4 — an asset as seen at the experience edge: a reference plus
 * whatever labels the multi-sourced, contestable label system carries
 * for it. The filter evaluates THIS — never an account, never a
 * balance. © Maher
 */

declare(strict_types=1);

namespace App\Domain\Aevum\Fabric\Data;

use Spatie\LaravelData\Data;

final class Asset extends Data
{
    /** @param list<string> $labelCategories */
    public function __construct(
        public readonly string $assetRef,
        public readonly array $labelCategories,
    ) {
    }
}
