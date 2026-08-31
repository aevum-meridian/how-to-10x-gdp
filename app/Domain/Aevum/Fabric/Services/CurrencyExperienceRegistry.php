<?php

/**
 * SPDX-License-Identifier: LicenseRef-AVL-2.0
 *
 * DEV-4.4 / A-§C.9 — the pluggable-experience registry. register()
 * refuses to surface any experience whose underlying Meridian issuance
 * policy is Core Riba. Meridian's I11 means such a policy cannot exist
 * in the system of record anyway — this gate is defense-in-depth at
 * the experience edge, so that even a record-layer failure could not
 * put a P·(1+r) product in front of a user.
 *
 * A-§C.14 holds throughout: this service reads the catalog and writes
 * ONLY experience_specs. It has no ledger vocabulary at all.
 *
 * © Maher
 */

declare(strict_types=1);

namespace App\Domain\Aevum\Fabric\Services;

use App\Domain\Aevum\Fabric\Data\ExperienceRegistration;
use App\Domain\Aevum\Fabric\Exceptions\CoreRibaExperienceException;
use App\Domain\Aevum\Fabric\Models\ExperienceSpec;
use Illuminate\Support\Facades\DB;

final class CurrencyExperienceRegistry
{
    /**
     * Register a currency experience, gating A-§C.9 first: the four
     * Core-Riba conjuncts (riba-eligible base ∧ prefixed-guaranteed
     * increase ∧ no genuine risk ∧ no value creation ∧ extraction)
     * are checked against the currency's issuance policy as stored in
     * the system of record.
     */
    public function register(ExperienceRegistration $registration): ExperienceSpec
    {
        $policy = DB::table('issuance_policies')
            ->where('currency_id', $registration->currencyId)
            ->first([
                'base_kind',
                'increase_kind',
                'risk_bearing',
                'value_creating',
                'extracts_from_counterparty',
            ]);

        if ($policy === null) {
            throw new \DomainException(
                'A-§C.9: an experience cannot be registered for a currency with no '
                .'issuance policy on record; there is nothing to check against.'
            );
        }

        $isCoreRiba = in_array($policy->base_kind, ['money', 'same_kind_fungible'], true)
            && $policy->increase_kind === 'prefixed_guaranteed'
            && filter_var($policy->risk_bearing, FILTER_VALIDATE_BOOL) === false
            && filter_var($policy->value_creating, FILTER_VALIDATE_BOOL) === false
            && filter_var($policy->extracts_from_counterparty, FILTER_VALIDATE_BOOL) === true;

        if ($isCoreRiba) {
            throw new CoreRibaExperienceException(
                'A-§C.9: this experience surfaces a Core Riba policy '
                .'(pre-fixed guaranteed increase on an idle riba-eligible base, '
                .'no risk borne, no value created, extraction from the counterparty). '
                .'Aevum refuses to surface it — independently of Meridian I11.'
            );
        }

        return ExperienceSpec::query()->create([
            'currency_id' => $registration->currencyId,
            'presentation' => $registration->presentation,
            'core_riba_checked' => true,
            'registered_at' => now(),
        ]);
    }
}
