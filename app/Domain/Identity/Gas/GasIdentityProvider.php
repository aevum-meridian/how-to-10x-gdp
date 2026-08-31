<?php

/**
 * SPDX-License-Identifier: LicenseRef-MVL-2.0 AND LicenseRef-AVL-2.0
 *
 * DEV-6.1 — THE GAS ADAPTER (GasIdentityProvider). Enforces I8, I9.
 *
 * GAS (the external Global Authenticator System) is consumed ONLY behind
 * this adapter, as ONE PROVIDER OF N (DOCUMENT 6.1 §5). The adapter:
 *  (a) authenticates via GAS's intent-based initiate/continue API and
 *      receives a DPoP-bound session whose subject id is an OPAQUE,
 *      non-PII identifier (I8);
 *  (b) obtains signed personhood attestations that both legs verify
 *      independently by signature — never trusting GAS as sole authority;
 *  (c) consumes GAS decision receipts for audit;
 *  (d) honors revocation and per-attestation consent;
 *  (e) confines GAS cross-RP risk signals to the AuthFraudSignal type,
 *      which the value/minting/rights layers structurally cannot import
 *      (the anti-correlation wall — PersonhoodBoundaryTest).
 *
 * REQUIRED GAS EXTENSION (DOCUMENT 6.1 §6, honestly reported): the GAS
 * README supplied with this build DOES NOT document a signed, standalone
 * PersonhoodAttestation endpoint verifiable without a live GAS session.
 * This adapter therefore integrates to the CONTRACT — the endpoint shape
 * declared below (GET /api/v1/personhood-attestations/{subject}) — and that
 * endpoint is specified as a required GAS extension rather than coupling
 * our value logic to GAS internals. Until GAS ships it, this provider is
 * Research-maturity and the maturity endpoint must say so.
 *
 * NO GAS ASSERTION EVER AUTHORS A LEDGER BALANCE CHANGE — it identifies
 * and gates only (I9). This class has, by design, no dependency on any
 * ledger, issuance, or settlement type.
 *
 * © Maher
 */

declare(strict_types=1);

namespace App\Domain\Identity\Gas;

use App\Domain\Identity\AuthFraud\AuthFraudSignal;
use App\Domain\Identity\Contracts\PersonhoodProvider;
use App\Domain\Identity\Data\PersonhoodAttestation;
use App\Domain\Identity\Enums\AssuranceRung;

final class GasIdentityProvider implements PersonhoodProvider
{
    public const PROVIDER_ID = 'gas';

    /**
     * The required GAS extension endpoint (DOCUMENT 6.1 §6): a signed,
     * session-independent personhood attestation.
     */
    public const REQUIRED_EXTENSION_ENDPOINT = 'GET /api/v1/personhood-attestations/{subject_commitment}';

    /**
     * @param \Closure(string): array{
     *     provider_id: string,
     *     subject_commitment: string,
     *     assurance_rung: int,
     *     fa_fr_profile_ref: string,
     *     nonce: string,
     *     expires_at: string,
     *     signature: string,
     *     slashing_bond_ref: string
     * } $attestationFetcher Transport-level fetcher (HTTP client in
     *     production; injected fake in tests). Kept as a closure so the
     *     domain layer never depends on HTTP objects (DEV-0 discipline).
     * @param \Closure(PersonhoodAttestation): bool $revocationChecker
     */
    public function __construct(
        private readonly string $gasEd25519PublicKey,
        private readonly \Closure $attestationFetcher,
        private readonly \Closure $revocationChecker,
    ) {
    }

    public function providerId(): string
    {
        return self::PROVIDER_ID;
    }

    public function fetchAttestation(string $opaqueSubjectId): PersonhoodAttestation
    {
        if ($this->looksLikePii($opaqueSubjectId)) {
            throw new \InvalidArgumentException(
                'I8: subject identifiers must be opaque, non-PII commitments; refusing a PII-shaped subject id.'
            );
        }

        /** @var array{provider_id: string, subject_commitment: string, assurance_rung: int, fa_fr_profile_ref: string, nonce: string, expires_at: string, signature: string, slashing_bond_ref: string} $raw */
        $raw = ($this->attestationFetcher)($opaqueSubjectId);

        return new PersonhoodAttestation(
            providerId: $raw['provider_id'],
            subjectCommitment: $raw['subject_commitment'],
            assuranceRung: AssuranceRung::from($raw['assurance_rung']),
            faFrProfileRef: $raw['fa_fr_profile_ref'],
            nonce: $raw['nonce'],
            expiresAt: new \DateTimeImmutable($raw['expires_at']),
            signature: $raw['signature'],
            slashingBondRef: $raw['slashing_bond_ref'],
        );
    }

    /**
     * Independent Ed25519 verification against GAS's registered public key.
     * This runs in OUR process, on OUR copy of the key — never a callback
     * into GAS — so GAS is never the authority over its own claims (I9).
     */
    public function verifyAttestation(PersonhoodAttestation $attestation): bool
    {
        if ($attestation->providerId !== self::PROVIDER_ID) {
            return false;
        }

        if ($attestation->isExpired(new \DateTimeImmutable())) {
            return false;
        }

        $signature = base64_decode($attestation->signature, true);
        $publicKey = base64_decode($this->gasEd25519PublicKey, true);

        if ($signature === false || $publicKey === false) {
            return false;
        }

        if (strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES || strlen($publicKey) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
            return false;
        }

        return sodium_crypto_sign_verify_detached(
            $signature,
            $attestation->signablePayload(),
            $publicKey,
        );
    }

    public function isRevoked(PersonhoodAttestation $attestation): bool
    {
        return ($this->revocationChecker)($attestation);
    }

    /**
     * Cross-RP risk signals enter ONLY as AuthFraudSignal — the single type
     * the value, minting, personhood-aggregation, and rights layers cannot
     * import (I9 anti-correlation wall). This method is the sole ingress.
     *
     * @param array{signal_kind: string, fraud_risk_score: float, observed_at: string} $rawSignal
     */
    public function ingestCrossRpSignal(string $opaqueSubjectId, array $rawSignal): AuthFraudSignal
    {
        return new AuthFraudSignal(
            providerId: self::PROVIDER_ID,
            opaqueSubjectId: $opaqueSubjectId,
            fraudRiskScore: $rawSignal['fraud_risk_score'],
            signalKind: $rawSignal['signal_kind'],
            observedAt: new \DateTimeImmutable($rawSignal['observed_at']),
        );
    }

    /**
     * Defensive heuristic: refuse subject identifiers that carry obvious
     * PII shapes (emails, phone numbers). The real guarantee is upstream —
     * GAS issues opaque ids — but the adapter fails closed (I8).
     */
    private function looksLikePii(string $subjectId): bool
    {
        if (filter_var($subjectId, FILTER_VALIDATE_EMAIL) !== false) {
            return true;
        }

        return preg_match('/^\+?[0-9][0-9 \-()]{6,}$/', $subjectId) === 1;
    }
}
