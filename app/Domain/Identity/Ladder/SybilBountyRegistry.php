<?php

/**
 * SPDX-License-Identifier: LicenseRef-MVL-2.0 AND LicenseRef-AVL-2.0
 *
 * DEV-6.x / DOCUMENT 6.2 §2 — the standing public bounty for
 * discovering Sybil clusters. Reports target CLUSTERS (graph evidence),
 * never individuals; reporters may be pseudonymous (opaque commitment).
 * Awarding a bounty feeds the vesting-slash and review paths — it never
 * debits anyone's balance (I6: containment bounds future entitlement,
 * it does not punish existing credits). © Maher
 */

declare(strict_types=1);

namespace App\Domain\Identity\Ladder;

use App\Domain\Identity\Models\SybilBounty;

final class SybilBountyRegistry
{
    /**
     * File a cluster report. Evidence must implicate a cluster — a
     * report naming a single identity is refused: the bounty hunts
     * farms, not people.
     *
     * @param list<string> $clusterIdentityIds
     * @param array<string, mixed> $evidence
     */
    public function report(string $reporterCommitment, array $clusterIdentityIds, array $evidence): SybilBounty
    {
        if (trim($reporterCommitment) === '') {
            throw new \InvalidArgumentException('SYBIL BOUNTY: a reporter commitment is required (pseudonymous is fine; anonymous-untraceable is not).');
        }

        if (count(array_unique($clusterIdentityIds)) < 2) {
            throw new \InvalidArgumentException(
                'SYBIL BOUNTY: a report must implicate a CLUSTER of at least two identities; '
                .'the bounty targets farms, never individuals.'
            );
        }

        $bounty = new SybilBounty([
            'reporter_commitment' => $reporterCommitment,
            'cluster_evidence' => [
                'identity_ids' => array_values(array_unique($clusterIdentityIds)),
                'evidence' => $evidence,
            ],
            'status' => 'open',
        ]);
        $bounty->save();

        return $bounty;
    }

    /**
     * Resolve a report — award or reject, always with a public note
     * (the bounty is a standing PUBLIC process).
     */
    public function resolve(SybilBounty $bounty, bool $award, string $resolutionNote): SybilBounty
    {
        if ($bounty->status !== 'open') {
            throw new \DomainException('SYBIL BOUNTY: this report has already been resolved.');
        }

        if (trim($resolutionNote) === '') {
            throw new \InvalidArgumentException('SYBIL BOUNTY: a resolution must carry a public note.');
        }

        $bounty->status = $award ? 'awarded' : 'rejected';
        $bounty->resolution_note = $resolutionNote;
        $bounty->save();

        return $bounty;
    }
}
