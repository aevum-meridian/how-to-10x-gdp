<?php

/**
 * SPDX-License-Identifier: LicenseRef-MVL-2.0
 *
 * DEV-4.5 — the anti-Goodhart throttle decision (DOCUMENT 2.3 §3):
 * θ ∈ [0,1] multiplies the FUTURE mint cap and appears in no term
 * affecting existing balances. The faucet, never the reservoir. © Maher
 */

declare(strict_types=1);

namespace App\Domain\Meridian\Policy\Data;

use Spatie\LaravelData\Data;

final class ThrottleDecision extends Data
{
    public function __construct(
        public readonly string $currencyId,
        public readonly float $divergence,
        public readonly float $threshold,
        public readonly float $theta,
    ) {
    }
}
