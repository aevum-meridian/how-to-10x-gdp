<?php

/**
 * SPDX-License-Identifier: LicenseRef-MVL-2.0 AND LicenseRef-AVL-2.0
 *
 * The interface every personhood provider adapter must satisfy —
 * DOCUMENT 6.1 §2 and DEV-6.1. Personhood is a third party to both legs,
 * owned by neither (I9): each provider is ONE OF N, and neither Meridian
 * nor Aevum is ever itself the personhood authority.
 *
 * Deliberately absent from this surface: any method exposing cross-context
 * behavioral or reputation signals. Those are confined to the AuthFraud
 * namespace and may never traverse this contract (the anti-correlation
 * wall, PersonhoodBoundaryTest).
 *
 * © Maher
 */

declare(strict_types=1);

namespace App\Domain\Identity\Contracts;

use App\Domain\Identity\Data\PersonhoodAttestation;

interface PersonhoodProvider
{
    public function providerId(): string;

    /**
     * Obtain a signed, session-independent personhood attestation for an
     * opaque subject. The attestation must be independently verifiable via
     * verifyAttestation() without a live provider session (DOCUMENT 6.1 §6).
     */
    public function fetchAttestation(string $opaqueSubjectId): PersonhoodAttestation;

    /**
     * Independent signature verification against the provider's registered
     * public key. Both legs run this check themselves — a forged attestation
     * must defeat both legs' independent checks, not one shared check (I9).
     */
    public function verifyAttestation(PersonhoodAttestation $attestation): bool;

    /**
     * Honor provider-side revocation (DOCUMENT 6.1 §5(d)).
     */
    public function isRevoked(PersonhoodAttestation $attestation): bool;
}
