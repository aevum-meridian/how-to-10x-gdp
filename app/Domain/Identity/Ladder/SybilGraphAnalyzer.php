<?php

/**
 * SPDX-License-Identifier: LicenseRef-MVL-2.0 AND LicenseRef-AVL-2.0
 *
 * DEV-6.x / DOCUMENT 6.2 §2 — Sybil scoring on GRAPH STRUCTURE, with
 * the score EXPLAINABLE and APPEALABLE — never a black-box exclusion.
 *
 * Design constraints, per the ladder's ethic:
 *  - Targets CLUSTERS (shared attesters, closed attestation loops),
 *    never demographic or behavioral profiles of an individual.
 *  - Deterministic, pure arithmetic over the attestation graph — the
 *    same discipline as Tier-0 (no ML on this path; a model may at most
 *    propose REVIEW, never act).
 *  - The output THROTTLES (feeds vesting/review), never excludes: a
 *    real but unusual person is slowed, not denied (DOCUMENT 6.2 §2).
 *  - Every score carries the reasons that produced it, and the score
 *    is appealable through the same appeal path as the determination.
 * © Maher
 */

declare(strict_types=1);

namespace App\Domain\Identity\Ladder;

use App\Domain\Identity\Ladder\Data\SybilScore;

final class SybilGraphAnalyzer
{
    /** Above this share of attesters overlapping with a sibling identity, flag clustering. */
    public const OVERLAP_THRESHOLD = 0.5;

    /** A component whose members attest only for each other is a closed loop. */
    public const CLOSED_LOOP_MIN_SIZE = 3;

    /**
     * Score one identity's position in the attestation graph.
     *
     * @param list<string> $subjectAttesterIds attesters vouching for the subject
     * @param array<string, list<string>> $siblingAttesterSets attester lists of other identities, keyed by identity id
     */
    public function score(string $identityId, array $subjectAttesterIds, array $siblingAttesterSets): SybilScore
    {
        $reasons = [];
        $score = 0.0;

        $subjectSet = array_values(array_unique($subjectAttesterIds));

        if ($subjectSet === []) {
            return new SybilScore(
                identityId: $identityId,
                score: 0.0,
                reasons: ['No attestations to analyze.'],
                appealable: true,
            );
        }

        // Signal 1: attester overlap with sibling identities. A farm
        // reuses the same attesters across many manufactured identities.
        $maxOverlap = 0.0;
        $overlappingSiblings = 0;

        foreach ($siblingAttesterSets as $siblingAttesters) {
            $shared = count(array_intersect($subjectSet, $siblingAttesters));
            $overlap = $shared / count($subjectSet);

            if ($overlap >= self::OVERLAP_THRESHOLD) {
                $overlappingSiblings++;
            }

            $maxOverlap = max($maxOverlap, $overlap);
        }

        if ($overlappingSiblings > 0) {
            $contribution = min(0.6, 0.2 * $overlappingSiblings);
            $score += $contribution;
            $reasons[] = sprintf(
                '%d sibling identit%s share ≥%d%% of this identity\'s attesters (max overlap %d%%). '
                .'What would change the outcome: attestations from parties outside the shared set.',
                $overlappingSiblings,
                $overlappingSiblings === 1 ? 'y' : 'ies',
                (int) (self::OVERLAP_THRESHOLD * 100),
                (int) round($maxOverlap * 100),
            );
        }

        // Signal 2: closed attestation loop — the subject's attesters
        // are all themselves identities in the sibling set attested by
        // the subject's cluster (a component sealed off from the wider
        // graph).
        $siblingIds = array_keys($siblingAttesterSets);
        $attestersInsideCluster = count(array_intersect($subjectSet, $siblingIds));

        if (
            count($subjectSet) >= self::CLOSED_LOOP_MIN_SIZE - 1
            && $attestersInsideCluster === count($subjectSet)
        ) {
            $score += 0.4;
            $reasons[] = 'Every attester is itself a member of the analyzed cluster (closed attestation loop). '
                .'What would change the outcome: an attestation from outside the loop.';
        }

        if ($reasons === []) {
            $reasons[] = 'No clustering signal: attesters are sufficiently independent.';
        }

        return new SybilScore(
            identityId: $identityId,
            score: min(1.0, $score),
            reasons: $reasons,
            appealable: true,
        );
    }
}
