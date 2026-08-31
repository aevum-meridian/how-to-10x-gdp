<?php

/**
 * SPDX-License-Identifier: LicenseRef-AVL-2.0
 *
 * DEV-4.4 — the four-rung identity ladder, CONSUMING external
 * personhood (I9): the ladder never computes personhood itself; it
 * maps a PersonhoodDetermination (produced by the aggregation layer
 * over GAS/other provider attestations) to what the experience layer
 * may unlock. Age-gating fails closed (child-safety floor): an
 * unverified age is treated as a minor's.
 *
 * The I9 wall holds here as everywhere: this class consumes the
 * aggregation layer's OUTPUT type only — no provider risk signal, no
 * cross-context score, is visible from this side of the membrane.
 *
 * © Maher
 */

declare(strict_types=1);

namespace App\Domain\Aevum\Identity;

use App\Domain\Identity\Aggregation\PersonhoodDetermination;
use App\Domain\Identity\Enums\AssuranceRung;

final class IdentityLadder
{
    /**
     * What each rung unlocks (DOCUMENT 4.4): Rung 0 — hold and swap,
     * no verification; Rung 1 — probationary dividend pool under a
     * hard constitutional cap; Rung 2 — regulated features where law
     * requires; Rung 3 — full $UNA and one-human-one-vote.
     *
     * @return list<string>
     */
    public function entitlementsFor(PersonhoodDetermination $determination): array
    {
        return match ($determination->effectiveRung) {
            AssuranceRung::Rung0 => ['hold', 'swap'],
            AssuranceRung::Rung1 => ['hold', 'swap', 'probationary_dividend_pool'],
            AssuranceRung::Rung2 => ['hold', 'swap', 'probationary_dividend_pool', 'regulated_features'],
            AssuranceRung::Rung3 => [
                'hold', 'swap', 'probationary_dividend_pool',
                'regulated_features', 'full_una', 'one_human_one_vote',
            ],
        };
    }

    /**
     * Age-gating fails CLOSED: access to an age-restricted experience
     * requires verified adulthood; absence of verification means no.
     */
    public function mayAccessAgeRestricted(?bool $verifiedAdult): bool
    {
        return $verifiedAdult === true;
    }
}
