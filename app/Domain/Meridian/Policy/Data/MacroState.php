<?php

/**
 * SPDX-License-Identifier: LicenseRef-MVL-2.0
 * DEV-4.5 — the aggregate macro observation. Read-only by construction:
 * observing produces this value object and writes nothing. © Maher
 */

declare(strict_types=1);

namespace App\Domain\Meridian\Policy\Data;

use Spatie\LaravelData\Data;

final class MacroState extends Data
{
    /**
     * @param array<string, int> $outstandingSupplyByCurrency Minor units,
     *     keyed by currency id.
     * @param array<string, float> $throttleByCurrency Current θ per
     *     currency (1.0 = unthrottled).
     * @param list<string> $firedBreakerCurrencyIds
     */
    public function __construct(
        public readonly array $outstandingSupplyByCurrency,
        public readonly array $throttleByCurrency,
        public readonly array $firedBreakerCurrencyIds,
        public readonly int $observedAtUnix,
    ) {
    }
}
