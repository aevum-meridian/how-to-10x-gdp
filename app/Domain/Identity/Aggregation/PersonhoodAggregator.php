<?php

/**
 * SPDX-License-Identifier: LicenseRef-MVL-2.0 AND LicenseRef-AVL-2.0
 *
 * Multi-provider personhood aggregation — DOCUMENT 6.1 §3/§4, DEV-6.x.
 *
 * A user's effective personhood is an aggregate over attestations from
 * independent providers, computed by a PUBLISHED, VERSIONED algorithm.
 * No single provider is dispositive for a constitutional-grade
 * determination. Every denial is explainable and appealable (the decision
 * receipt pattern), because denial of personhood is a high-risk
 * consequential decision.
 *
 * I9 WALL: this class lives in the personhood-aggregation layer and is
 * therefore FORBIDDEN from importing the authentication-fraud signal
 * namespace — a provider's cross-context behavioral signal may never feed
 * a personhood-aggregation decision. PersonhoodBoundaryTest enforces this
 * (its scanner would flag even a mention of that type's name here).
 *
 * © Maher
 */

declare(strict_types=1);

namespace App\Domain\Identity\Aggregation;

use App\Domain\Identity\Contracts\PersonhoodProvider;
use App\Domain\Identity\Data\PersonhoodAttestation;
use App\Domain\Identity\Enums\AssuranceRung;

final class PersonhoodAggregator
{
    /**
     * The published, versioned aggregation algorithm identifier stored in
     * identities.aggregation_version (DOCUMENT 6.1 §3).
     */
    public const AGGREGATION_VERSION = 'agg-v1.0';

    /**
     * Minimum number of independent providers required for a
     * constitutional-grade (Rung 3) determination: no single provider
     * may be dispositive.
     */
    public const CONSTITUTIONAL_GRADE_MIN_PROVIDERS = 2;

    /** @param array<string, PersonhoodProvider> $providers keyed by provider id */
    public function __construct(private readonly array $providers)
    {
    }

    /**
     * Aggregate verified attestations into an effective rung, with a
     * human-readable explanation (explainable denial, DOCUMENT 6.1 §4).
     *
     * @param list<PersonhoodAttestation> $attestations
     */
    public function determine(array $attestations): PersonhoodDetermination
    {
        $verified = [];
        $explanations = [];

        foreach ($attestations as $attestation) {
            $provider = $this->providers[$attestation->providerId] ?? null;

            if ($provider === null) {
                $explanations[] = sprintf('Provider "%s" is not registered.', $attestation->providerId);

                continue;
            }

            if (! $provider->verifyAttestation($attestation)) {
                $explanations[] = sprintf('Attestation from "%s" failed independent signature/expiry verification.', $attestation->providerId);

                continue;
            }

            if ($provider->isRevoked($attestation)) {
                $explanations[] = sprintf('Attestation from "%s" has been revoked.', $attestation->providerId);

                continue;
            }

            $verified[$attestation->providerId] = $attestation;
        }

        $distinctProviders = count($verified);
        $maxRung = AssuranceRung::Rung0;

        foreach ($verified as $attestation) {
            if ($attestation->assuranceRung->value > $maxRung->value) {
                $maxRung = $attestation->assuranceRung;
            }
        }

        // No single provider dispositive for constitutional grade (Rung 3).
        if ($maxRung === AssuranceRung::Rung3 && $distinctProviders < self::CONSTITUTIONAL_GRADE_MIN_PROVIDERS) {
            $maxRung = AssuranceRung::Rung2;
            $explanations[] = sprintf(
                'Rung 3 requires at least %d independent providers; %d verified. What would change the outcome: a second independent provider attestation.',
                self::CONSTITUTIONAL_GRADE_MIN_PROVIDERS,
                $distinctProviders,
            );
        }

        if ($verified === []) {
            $explanations[] = 'No attestation could be verified. What would change the outcome: a valid, unexpired attestation from any registered provider.';
        }

        return new PersonhoodDetermination(
            effectiveRung: $maxRung,
            aggregationVersion: self::AGGREGATION_VERSION,
            verifiedProviderIds: array_keys($verified),
            explanation: $explanations === [] ? 'All submitted attestations verified.' : implode(' ', $explanations),
            appealable: true,
        );
    }
}
