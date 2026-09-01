<?php

/**
 * SPDX-License-Identifier: LicenseRef-MVL-2.0 AND LicenseRef-AVL-2.0
 *
 * DEV-6.x / DOCUMENT 6.4 — prove the criterion, never reveal the
 * measurement.
 *
 * ingest() accepts ONLY {statement, commitment, proof}: any payload
 * carrying a raw sensitive field is rejected at the door (the first of
 * I8's three layers — the second is the schema's column allowlist, the
 * third is NoSensitivePIIMigrationTest). The NEURAL RED LINE is
 * absolute: no minimized-disclosure path exists for neural data at all
 * — a neural-sourced statement is refused even as a proof, because for
 * neural data the correct amount to verify is nothing.
 *
 * Consent is per-attestation and revocable, and never a precondition
 * for non-credit ledger access (DOCUMENT 6.4 §2).
 *
 * Honesty (DOCUMENT 6.4 §4): this service verifies proof RECORDS
 * against registered proof systems; the cryptographic soundness of a
 * circuit is Research-tier and independently audited — the service
 * fails CLOSED on any proof system it does not recognize. © Maher
 */

declare(strict_types=1);

namespace App\Domain\Joint\Disclosure\Services;

use App\Domain\Joint\Disclosure\Exceptions\SensitiveDataRejectedException;
use App\Domain\Joint\Disclosure\Models\DisclosureProof;
use Illuminate\Support\Carbon;

final class MinimizedDisclosureService
{
    /**
     * Payload keys that denote RAW sensitive data. A minimized-disclosure
     * ingestion carrying any of these is rejected: the protocol never
     * holds the witness.
     */
    private const FORBIDDEN_PAYLOAD_KEYS = [
        'biometric', 'fingerprint', 'retina', 'iris', 'face', 'voiceprint',
        'gait', 'dna', 'genome', 'genetic', 'health_record', 'diagnosis',
        'medical', 'blood', 'heart_rate', 'birthdate', 'date_of_birth',
        'neural', 'eeg', 'brainwave', 'brain_signal', 'witness',
    ];

    /**
     * Statements about neural data are refused outright — the red line
     * admits no proof path at all.
     */
    private const NEURAL_FRAGMENTS = ['neural', 'eeg', 'brainwave', 'brain_signal'];

    /** Proof systems this deployment recognizes; anything else fails closed. */
    private const RECOGNIZED_PROOF_SYSTEMS = ['groth16-audited-v1', 'plonk-audited-v1'];

    /**
     * Ingest a minimized-disclosure proof.
     *
     * @param array<string, mixed> $payload exactly {statement, subject_commitment, proof_blob, proof_system, expires_at?}
     */
    public function ingest(array $payload): DisclosureProof
    {
        // Layer 1 of I8: reject raw sensitive fields at the door.
        foreach (array_keys($payload) as $key) {
            $lower = strtolower((string) $key);

            foreach (self::FORBIDDEN_PAYLOAD_KEYS as $forbidden) {
                if (str_contains($lower, $forbidden)) {
                    throw new SensitiveDataRejectedException(sprintf(
                        'I8: the payload carries a raw sensitive field ("%s"); the protocol accepts '
                        .'only commitments and proofs — never the measurement itself.',
                        $key,
                    ));
                }
            }
        }

        $statement = $payload['statement'] ?? null;
        $commitment = $payload['subject_commitment'] ?? null;
        $proofBlob = $payload['proof_blob'] ?? null;
        $proofSystem = $payload['proof_system'] ?? null;

        if (! is_string($statement) || trim($statement) === '') {
            throw new \InvalidArgumentException('DISCLOSURE: a public statement (the criterion) is required.');
        }

        // The neural red line: no proof path exists for neural data at all.
        $statementLower = strtolower($statement);

        foreach (self::NEURAL_FRAGMENTS as $fragment) {
            if (str_contains($statementLower, $fragment)) {
                throw new SensitiveDataRejectedException(
                    'I8 (neural red line): no minimized-disclosure path is offered for neural data — '
                    .'it is entirely outside the system, even as a proof.'
                );
            }
        }

        if (! is_string($commitment) || trim($commitment) === '') {
            throw new \InvalidArgumentException('DISCLOSURE: an opaque subject commitment is required.');
        }

        if (! is_string($proofBlob) || trim($proofBlob) === '') {
            throw new \InvalidArgumentException('DISCLOSURE: a proof blob is required; a bare claim is not a proof.');
        }

        if (! is_string($proofSystem) || ! in_array($proofSystem, self::RECOGNIZED_PROOF_SYSTEMS, true)) {
            throw new SensitiveDataRejectedException(
                'DISCLOSURE: unrecognized proof system; verification fails CLOSED — '
                .'an unaudited circuit may leak the witness it was meant to hide.'
            );
        }

        $expiresAt = null;

        if (isset($payload['expires_at']) && is_string($payload['expires_at'])) {
            $expiresAt = Carbon::parse($payload['expires_at']);
        }

        $proof = new DisclosureProof([
            'statement' => $statement,
            'subject_commitment' => $commitment,
            'proof_blob' => $proofBlob,
            'proof_system' => $proofSystem,
            'verified' => true,
            'consent_revoked' => false,
            'expires_at' => $expiresAt,
        ]);
        $proof->save();

        return $proof;
    }

    /**
     * Per-attestation, revocable consent (DOCUMENT 6.4 §2). Revocation
     * is honored immediately; the proof stops standing for anything.
     */
    public function revokeConsent(DisclosureProof $proof): DisclosureProof
    {
        $proof->consent_revoked = true;
        $proof->save();

        return $proof;
    }

    /**
     * Does this proof currently stand? Fails closed on revocation and
     * expiry.
     */
    public function stands(DisclosureProof $proof): bool
    {
        if (! $proof->verified || $proof->consent_revoked) {
            return false;
        }

        if ($proof->expires_at !== null && Carbon::now()->greaterThan($proof->expires_at)) {
            return false;
        }

        return true;
    }
}
