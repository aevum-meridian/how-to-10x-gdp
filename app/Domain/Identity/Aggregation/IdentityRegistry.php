<?php

/**
 * SPDX-License-Identifier: LicenseRef-MVL-2.0 AND LicenseRef-AVL-2.0
 *
 * DEV-6.x / DOCUMENT 6.1 §3/§4 — persists a PersonhoodAggregator
 * determination as the identity's record: versioned algorithm id,
 * provider-attestation SUMMARIES (provider id + rung + opaque
 * commitment — never raw PII, I8), the explainable outcome, and the
 * appeal state. The Sybil score stored here is explainable and
 * appealable — it may throttle (vesting/review), never exclude.
 *
 * I9 WALL: this class is inside the personhood-aggregation layer and
 * therefore imports nothing from the authentication-fraud namespace;
 * PersonhoodBoundaryTest's scanner enforces that even a mention of
 * that type's name here fails the build. © Maher
 */

declare(strict_types=1);

namespace App\Domain\Identity\Aggregation;

use App\Domain\Identity\Data\PersonhoodAttestation;
use App\Domain\Identity\Enums\AppealStatus;
use App\Domain\Identity\Ladder\Data\SybilScore;
use App\Domain\Identity\Models\Identity;

final class IdentityRegistry
{
    /**
     * Record (or refresh) the identity row for a determination.
     *
     * @param list<PersonhoodAttestation> $attestations
     */
    public function record(string $subjectCommitment, PersonhoodDetermination $determination, array $attestations): Identity
    {
        if (trim($subjectCommitment) === '') {
            throw new \InvalidArgumentException('IDENTITY: an opaque subject commitment is required.');
        }

        $summaries = [];

        foreach ($attestations as $attestation) {
            if (! in_array($attestation->providerId, $determination->verifiedProviderIds, true)) {
                continue;
            }

            // Summaries only: provider, rung, commitment. No PII field
            // exists on the attestation DTO to begin with (I8), and none
            // is invented here.
            $summaries[] = [
                'provider_id' => $attestation->providerId,
                'assurance_rung' => $attestation->assuranceRung->value,
                'subject_commitment' => $attestation->subjectCommitment,
                'expires_at' => $attestation->expiresAt->format(DATE_ATOM),
            ];
        }

        $identity = Identity::query()->where('subject_commitment', $subjectCommitment)->first();

        if ($identity === null) {
            $identity = new Identity([
                'subject_commitment' => $subjectCommitment,
                'appeal_status' => AppealStatus::None,
            ]);
        }

        $identity->aggregation_version = $determination->aggregationVersion;
        $identity->effective_rung = $determination->effectiveRung->value;
        $identity->provider_attestations = $summaries;
        $identity->explanation = $determination->explanation;
        $identity->save();

        return $identity;
    }

    /**
     * Attach an explainable Sybil score. Scoring THROTTLES — it may
     * not lower the effective rung by itself: rung changes flow only
     * through a fresh aggregation or the appeal process.
     */
    public function attachSybilScore(Identity $identity, SybilScore $score): Identity
    {
        if ($score->identityId !== $identity->id) {
            throw new \InvalidArgumentException('IDENTITY: this score was computed for a different identity.');
        }

        $identity->sybil_risk_score = sprintf('%.4F', $score->score);
        $identity->explanation = $identity->explanation.' Sybil analysis: '.implode(' ', $score->reasons);
        $identity->save();

        return $identity;
    }

    /**
     * Open an appeal — always available (DOCUMENT 6.1 §4: denial of
     * personhood is a high-risk consequential decision).
     */
    public function openAppeal(Identity $identity): Identity
    {
        if ($identity->appeal_status === AppealStatus::Open) {
            return $identity;
        }

        $identity->appeal_status = AppealStatus::Open;
        $identity->save();

        return $identity;
    }

    /**
     * Resolve an appeal with a human decision and a stated reason.
     */
    public function resolveAppeal(Identity $identity, bool $upheld, string $reason): Identity
    {
        if ($identity->appeal_status !== AppealStatus::Open) {
            throw new \DomainException('IDENTITY: no open appeal to resolve.');
        }

        if (trim($reason) === '') {
            throw new \InvalidArgumentException('IDENTITY: an appeal resolution must state its reason.');
        }

        $identity->appeal_status = $upheld ? AppealStatus::Upheld : AppealStatus::Denied;
        $identity->explanation = $identity->explanation.' Appeal '.($upheld ? 'upheld' : 'denied').': '.$reason;
        $identity->save();

        return $identity;
    }
}
