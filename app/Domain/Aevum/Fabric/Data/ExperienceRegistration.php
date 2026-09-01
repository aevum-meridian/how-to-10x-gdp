<?php

/**
 * SPDX-License-Identifier: LicenseRef-AVL-2.0
 *
 * DEV-4.4 — the input to CurrencyExperienceRegistry::register(): a
 * request to surface a Meridian currency as a pluggable experience.
 * The registry gates it through A-§C.9 before any row exists. © Maher
 */

declare(strict_types=1);

namespace App\Domain\Aevum\Fabric\Data;

use Spatie\LaravelData\Data;

final class ExperienceRegistration extends Data
{
    /** @param array<string, mixed> $presentation */
    public function __construct(
        public readonly string $currencyId,
        public readonly array $presentation,
    ) {
    }
}
