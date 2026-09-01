<?php

/**
 * SPDX-License-Identifier: LicenseRef-AVL-2.0
 *
 * DEV-4.4 / A-§C.12 — the INERT record a Tier-1 advisory proposer may
 * emit. Any ML in Aevum lives only behind this type: it proposes, a
 * human or a pure Tier-0 rule disposes. An AdvisoryProposal executes
 * nothing, schedules nothing, and touches nothing — it is data.
 * © Maher
 */

declare(strict_types=1);

namespace App\Domain\Aevum\Fabric\Tier1;

use Spatie\LaravelData\Data;

final class AdvisoryProposal extends Data
{
    /** @param array<string, mixed> $suggestion */
    public function __construct(
        public readonly string $proposerId,
        public readonly string $subject,
        public readonly array $suggestion,
        public readonly string $rationale,
    ) {
    }
}
