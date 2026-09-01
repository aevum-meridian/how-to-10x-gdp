<?php

/**
 * SPDX-License-Identifier: LicenseRef-MVL-2.0
 *
 * DEV-8.3 / DOCUMENT 8.3 — real-time proof that reserve-backed value is
 * actually backed. © Maher
 *
 * A reserve-backed currency is a promise: each unit backed 1:1 by
 * custodied real-world value. This service converts the opaque legacy
 * promise into a structural, verifiable one:
 *
 *   1. ingest() accepts ONLY Ed25519-signed attestations from a
 *      registered, unrevoked, licensed custodian, replay-bounded by a
 *      per-message unique nonce, and stores them append-only. If the
 *      attested figure falls below net issuance — a SHORTFALL — the
 *      crisis path fires AUTOMATICALLY inside the same call: the
 *      circuit breaker halts the currency's automatic issuance
 *      (DOCUMENT 4.5) and the disclosure clock starts. The auto-trigger
 *      removes the discretion to delay disclosure of a shortfall, which
 *      is the moment of greatest temptation to stay silent.
 *
 *   2. buildReserveDeposit() supplies the Issuance Engine's mintReserve()
 *      with the attested figure from the latest FRESH attestation, so a
 *      mint beyond attested reserves is structurally impossible — the
 *      refusal lives in DEV-4.2's guard, this service merely refuses to
 *      fabricate a figure it does not have.
 *
 *   3. latestFor() exposes the attestation for independent verification —
 *      the user's right to proof-of-backing.
 *
 * Honest caveats (DOCUMENT 8.3 §5): proof-of-backing is only as honest
 * as the custodian's attestation; the guarantee is CONDITIONAL on
 * custodian honesty, which is why custodians are licensed and the
 * attestation is signed. Freshness reduces but does not eliminate the
 * window between a real shortfall and its attestation. And this proof is
 * meaningful for reserve-backed currencies ONLY — contribution credits
 * and bridged crypto are backed differently, and claiming otherwise
 * would itself be a form of Gharar.
 */

declare(strict_types=1);

namespace App\Domain\Meridian\Reserve\Services;

use App\Domain\Joint\Crisis\Services\CrisisService;
use App\Domain\Meridian\Issuance\Data\ReserveDeposit;
use App\Domain\Meridian\Ledger\Enums\SystemAccountRole;
use App\Domain\Meridian\Ledger\Models\Account;
use App\Domain\Meridian\Ledger\Models\Currency;
use App\Domain\Meridian\Policy\Enums\BreakerReason;
use App\Domain\Meridian\Policy\Services\PolicyEngineService;
use App\Domain\Meridian\Reserve\Exceptions\AttestationRejectedException;
use App\Domain\Meridian\Reserve\Exceptions\StaleAttestationException;
use App\Domain\Meridian\Reserve\Models\ReserveAttestation;
use App\Domain\Meridian\Reserve\Models\ReserveCustodian;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

final class ReserveAttestationService
{
    /**
     * Freshness floor: an attestation older than this cannot back a new
     * mint. A real-world parameter with a real-world floor (§5) — the
     * window between a real shortfall and its attestation is never zero,
     * but it is bounded.
     */
    public const MAX_ATTESTATION_AGE_SECONDS = 86_400;

    public function __construct(
        private readonly PolicyEngineService $policy,
        private readonly CrisisService $crisis,
    ) {
    }

    /**
     * Register a licensed custodian's Ed25519 verification key. Only a
     * registered, unrevoked custodian may attest ("tunnels are licensed
     * institutions", DOCUMENT 3.3).
     */
    public function registerCustodian(
        Currency $currency,
        string $name,
        string $publicKeyHex,
        string $licenseRef,
    ): ReserveCustodian {
        if (strlen($publicKeyHex) !== 64 || preg_match('/^[0-9a-f]{64}$/', $publicKeyHex) !== 1) {
            throw new AttestationRejectedException(
                'Custodian registration refused: the verification key is not a valid Ed25519 public key (64 hex chars expected).'
            );
        }

        if ($licenseRef === '') {
            throw new AttestationRejectedException(
                'Custodian registration refused: a custodian without a license reference is not a licensed institution.'
            );
        }

        $custodian = new ReserveCustodian([
            'currency_id' => $currency->id,
            'name' => $name,
            'public_key' => $publicKeyHex,
            'license_ref' => $licenseRef,
        ]);
        $custodian->save();

        return $custodian;
    }

    /**
     * Ingest one signed attestation of reserves held. Verifies the
     * custodian's signature over the canonical message, refuses replays
     * (unique nonce), stores append-only — and if the attested figure is
     * below net issuance, fires the crisis path in the SAME call.
     */
    public function ingest(
        ReserveCustodian $custodian,
        int $attestedReserveMinor,
        string $nonce,
        string $signatureHex,
        \DateTimeImmutable $attestedAt,
    ): ReserveAttestation {
        if ($custodian->revoked_at !== null) {
            throw new AttestationRejectedException(
                "Attestation refused: custodian {$custodian->name} was revoked and can no longer speak for these reserves."
            );
        }

        if ($attestedReserveMinor < 0) {
            throw new AttestationRejectedException(
                'Attestation refused: a negative reserve figure is not an attestation, it is an error.'
            );
        }

        if ($nonce === '' || $signatureHex === '') {
            throw new AttestationRejectedException(
                'Attestation refused: an unsigned or un-nonced attestation carries no proof.'
            );
        }

        $message = self::attestationMessage(
            $custodian->id,
            $custodian->currency_id,
            $attestedReserveMinor,
            $nonce,
            $attestedAt,
        );

        $signature = @hex2bin($signatureHex);
        $publicKey = @hex2bin($custodian->public_key);

        if (
            $signature === false || strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES
            || $publicKey === false || strlen($publicKey) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES
            || ! sodium_crypto_sign_verify_detached($signature, $message, $publicKey)
        ) {
            throw new AttestationRejectedException(
                'Attestation refused: the signature does not verify against the registered custodian key. '
                .'Proof-of-backing is only as honest as the signature that carries it.'
            );
        }

        return DB::transaction(function () use ($custodian, $attestedReserveMinor, $nonce, $signatureHex, $attestedAt): ReserveAttestation {
            $currency = Currency::query()->findOrFail($custodian->currency_id);

            try {
                $attestation = new ReserveAttestation([
                    'custodian_id' => $custodian->id,
                    'currency_id' => $custodian->currency_id,
                    'attested_reserve_minor' => $attestedReserveMinor,
                    'nonce' => $nonce,
                    'signature' => $signatureHex,
                    'attested_at' => $attestedAt->format(DATE_ATOM),
                ]);
                $attestation->save();
            } catch (UniqueConstraintViolationException) {
                throw new AttestationRejectedException(
                    "Attestation refused: nonce {$nonce} was already used — a replayed attestation is refused."
                );
            }

            // DOCUMENT 8.3 §4 — the automatic crisis trigger. If the fresh
            // attestation reveals reserves below net issuance, the breaker
            // fires HERE, in the same transaction that recorded the
            // shortfall. No human gets to decide whether to disclose.
            $outstanding = $this->outstandingSupply($currency);

            if ($attestedReserveMinor < $outstanding) {
                // Technical halt (DOCUMENT 4.5) AND institutional crisis
                // (DOCUMENT 8.1 §4) fire together, automatically: the
                // disclosure clock starts at the attestation itself,
                // without waiting for human discovery.
                $this->policy->fireCircuitBreaker($currency, BreakerReason::ReserveShortfall);
                $this->crisis->declareReserveShortfall($currency->code, $attestedReserveMinor, $outstanding);
            }

            return $attestation;
        });
    }

    /**
     * The latest attestation for a currency — the record any user may
     * independently verify (the right to proof-of-backing).
     */
    public function latestFor(Currency $currency): ?ReserveAttestation
    {
        return ReserveAttestation::query()
            ->where('currency_id', $currency->id)
            ->orderByDesc('attested_at')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * Verify a stored attestation against its custodian's registered
     * key — the check any user can run themselves.
     */
    public function verify(ReserveAttestation $attestation): bool
    {
        $custodian = ReserveCustodian::query()->find($attestation->custodian_id);

        if ($custodian === null) {
            return false;
        }

        $message = self::attestationMessage(
            $custodian->id,
            $attestation->currency_id,
            $attestation->attested_reserve_minor,
            $attestation->nonce,
            $attestation->attested_at->toDateTimeImmutable(),
        );

        $signature = @hex2bin($attestation->signature);
        $publicKey = @hex2bin($custodian->public_key);

        return $signature !== false && strlen($signature) === SODIUM_CRYPTO_SIGN_BYTES
            && $publicKey !== false && strlen($publicKey) === SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES
            && sodium_crypto_sign_verify_detached($signature, $message, $publicKey);
    }

    /**
     * Build the ReserveDeposit that mintReserve() demands, sourcing the
     * attested figure from the latest FRESH attestation. Without a fresh
     * attestation there is no figure to mint against — this method fails
     * CLOSED rather than fabricate one.
     *
     * @param non-empty-string $recipientAccountId
     * @param positive-int $amountMinor
     * @param non-empty-string $idempotencyKey
     */
    public function buildReserveDeposit(
        Currency $currency,
        string $recipientAccountId,
        int $amountMinor,
        string $idempotencyKey,
    ): ReserveDeposit {
        $attestation = $this->latestFor($currency);

        if ($attestation === null) {
            throw new StaleAttestationException(
                "No reserve attestation exists for currency {$currency->code}: without attested reserves there is nothing to mint against. Refused."
            );
        }

        $ageSeconds = (int) $attestation->attested_at->diffInSeconds(now());

        if ($ageSeconds > self::MAX_ATTESTATION_AGE_SECONDS) {
            throw new StaleAttestationException(
                "The latest attestation for {$currency->code} is {$ageSeconds}s old — beyond the freshness floor of "
                .self::MAX_ATTESTATION_AGE_SECONDS.'s. A stale attestation cannot back a new mint. Refused.'
            );
        }

        $attested = max(0, $attestation->attested_reserve_minor);

        return new ReserveDeposit(
            recipientAccountId: $recipientAccountId,
            amountMinor: $amountMinor,
            attestedReserveMinor: $attested,
            custodyAttestationRef: 'attestation:'.$attestation->id,
            idempotencyKey: $idempotencyKey,
        );
    }

    /**
     * The canonical message a custodian signs. Deterministic, versioned
     * by its leading tag, and bound to custodian + currency + figure +
     * nonce + time — so an attestation cannot be replayed across
     * custodians, currencies, figures, or moments.
     */
    public static function attestationMessage(
        string $custodianId,
        string $currencyId,
        int $attestedReserveMinor,
        string $nonce,
        \DateTimeImmutable $attestedAt,
    ): string {
        return implode('|', [
            'reserve-attestation',
            $custodianId,
            $currencyId,
            (string) $attestedReserveMinor,
            $nonce,
            // Normalized to UTC so signer and verifier always render the
            // same canonical string regardless of storage timezone.
            $attestedAt->setTimezone(new \DateTimeZone('UTC'))->format(DATE_ATOM),
        ]);
    }

    /**
     * Net issuance = the negative mirror carried by the ISSUANCE system
     * account (everything minted, net of burns).
     */
    private function outstandingSupply(Currency $currency): int
    {
        $issuance = Account::query()
            ->where('currency_id', $currency->id)
            ->where('system_role', SystemAccountRole::Issuance->value)
            ->first();

        return $issuance === null ? 0 : -1 * $issuance->balance_minor;
    }
}
