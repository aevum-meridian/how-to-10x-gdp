<?php

/**
 * SPDX-License-Identifier: LicenseRef-AVL-2.0
 *
 * DEV-4.4 — a user's own ethical rules for their wallet (DOCUMENT 0.5:
 * user-sovereign by default). Categories the user refuses outright,
 * categories the user wants warned about, everything else admitted.
 * © Maher
 */

declare(strict_types=1);

namespace App\Domain\Aevum\Fabric\Data;

use Spatie\LaravelData\Data;

final class UserRules extends Data
{
    /**
     * @param list<string> $refuseCategories
     * @param list<string> $warnCategories
     */
    public function __construct(
        public readonly string $userRef,
        public readonly array $refuseCategories = [],
        public readonly array $warnCategories = [],
    ) {
    }
}
