<?php

/**
 * SPDX-License-Identifier: LicenseRef-MVL-2.0 AND LicenseRef-AVL-2.0
 *
 * The provider-agnostic personhood attestation contract — DOCUMENT 6.1 §2,
 * DEV-6.1. Every provider (GAS and any other of the N) exposes attestations
 * conforming to this shape. It carries only: provider id, an OPAQUE subject
 * commitment (a ZK commitment or salted reference — NEVER raw biometric/PII,
 * per I8), the assurance rung, the provider's published FA/FR profile
 * reference, a unique nonce and expiry (replay/expiry discipline mirroring
 * I4), the provider's signature, and the provider's slashing-bond reference.
 *
 * Meridian and Aevum each verify the signature INDEPENDENTLY against the
 * provider's registered public key — never trusting GAS (or any provider)
 * as sole authority (I9).
 *
 * © Maher
 */

declare(strict_types=1);

namespace App\Domain\Identity\Data;

use App\Domain\Identity\Enums\AssuranceRung;
use Spatie\LaravelData\Data;

final class PersonhoodAttestation extends Data
{
    public function __construct(
        public readonly string $providerId,
        public readonly string $subjectCommitment,
        public readonly AssuranceRung $assuranceRung,
        public readonly string $faFrProfileRef,
        public readonly string $nonce,
        public readonly \DateTimeImmutable $expiresAt,
        public readonly string $signature,
        public readonly string $slashingBondRef,
    ) {
    }

    /**
     * Canonical byte payload over which the provider signature is computed.
     */
    public function signablePayload(): string
    {
        return implode('|', [
            $this->providerId,
            $this->subjectCommitment,
            (string) $this->assuranceRung->value,
            $this->faFrProfileRef,
            $this->nonce,
            $this->expiresAt->format(\DateTimeInterface::ATOM),
            $this->slashingBondRef,
        ]);
    }

    public function isExpired(\DateTimeImmutable $now): bool
    {
        return $now >= $this->expiresAt;
    }
}
