<?php

/**
 * SPDX-License-Identifier: LicenseRef-MVL-2.0
 *
 * DEV-4.5 — a bounded adjustment to FUTURE issuance parameters — the
 * only kind of write the Policy Engine can make (I7). Per-epoch movement
 * caps bound bad-input damage (DOCUMENT 4.5 "Failure modes"). © Maher
 */

declare(strict_types=1);

namespace App\Domain\Meridian\Policy\Data;

use Spatie\LaravelData\Data;

final class PolicyDelta extends Data
{
    /**
     * The maximum fractional movement of any parameter in one epoch: a
     * corrupted signal can move policy by at most this capped step.
     */
    public const MAX_EPOCH_MOVEMENT = 0.25;

    /**
     * @param float|null $rateLimitMultiplier Multiplier on the per-epoch
     *     mint cap (e.g. 0.9 = tighten 10%). Bounded by the movement cap.
     * @param int|null $newMaxSupply Replacement max supply (future mints
     *     only; never below current outstanding supply).
     * @param non-empty-string $justification
     */
    public function __construct(
        public readonly ?float $rateLimitMultiplier,
        public readonly ?int $newMaxSupply,
        public readonly string $justification,
    ) {
    }

    public function exceedsMovementCap(): bool
    {
        return $this->rateLimitMultiplier !== null
            && abs($this->rateLimitMultiplier - 1.0) > self::MAX_EPOCH_MOVEMENT;
    }
}
