<?php

/**
 * SPDX-License-Identifier: LicenseRef-MVL-2.0
 *
 * DEV-4.2 — ISSUANCE & PoVC ENGINE service. The only component permitted
 * to change net issuance, and it does so ONLY through the Ledger Core's
 * balanced mint/burn transactions — it never authors entries directly.
 *
 * Service-layer enforcement (the second of the three points):
 *  - I11: instantiateCurrency() evaluates the four-element Core-Riba
 *    predicate and refuses squarely-Core-Riba policies; the interpretive
 *    supremacy of permission applies ONLY at the boundary and never
 *    rescues a squarely-Core-Riba policy (DOCUMENT 2.1 §6.3).
 *  - I4:  mintContribution() verifies every signature against registered
 *    verifier pubkeys, counts DISTINCT rotation groups (independence, not
 *    M keys from one party), requires ≥ M, and marks the nonce consumed
 *    in the same atomic transaction as the mint.
 *  - I8:  attestation ingestion accepts only commitments/minimized-
 *    disclosure proofs, never raw measurements; neural-sourced currencies
 *    are refused at instantiateCurrency().
 *  - Reserve discipline: mintReserve() refuses mints that would exceed
 *    attested reserves; representBridged() mints 1:1 only against a
 *    confirmed source-chain lock.
 *
 * GAS note (I9): the personhood gate consumes the GAS adapter's signed
 * attestations upstream; no cross-RP risk score can reach any decision in
 * this class — the walled typed boundary (PersonhoodBoundaryTest) proves
 * the score cannot influence whether or how much anyone mints.
 *
 * © Maher
 */

declare(strict_types=1);

namespace App\Domain\Meridian\Issuance\Services;

use App\Domain\Meridian\Issuance\Data\BridgeLock;
use App\Domain\Meridian\Issuance\Data\CurrencyPolicy;
use App\Domain\Meridian\Issuance\Data\Redemption;
use App\Domain\Meridian\Issuance\Data\ReserveDeposit;
use App\Domain\Meridian\Issuance\Enums\IncreaseKind;
use App\Domain\Meridian\Issuance\Enums\IssuanceType;
use App\Domain\Meridian\Issuance\Exceptions\CoreRibaPolicyException;
use App\Domain\Meridian\Issuance\Exceptions\QuorumNotMetException;
use App\Domain\Meridian\Issuance\Exceptions\ReserveExceededException;
use App\Domain\Meridian\Issuance\Exceptions\SensitiveDataException;
use App\Domain\Meridian\Issuance\Models\Attestation;
use App\Domain\Meridian\Issuance\Models\IssuancePolicy;
use App\Domain\Meridian\Issuance\Models\Verifier;
use App\Domain\Meridian\Ledger\Data\EntryDraft;
use App\Domain\Meridian\Ledger\Data\TransactionDraft;
use App\Domain\Meridian\Ledger\Enums\AccountType;
use App\Domain\Meridian\Ledger\Enums\SystemAccountRole;
use App\Domain\Meridian\Ledger\Enums\TransactionKind;
use App\Domain\Meridian\Ledger\Models\Account;
use App\Domain\Meridian\Ledger\Models\Currency;
use App\Domain\Meridian\Ledger\Models\LedgerTransaction;
use App\Domain\Meridian\Ledger\Services\LedgerService;
use App\Domain\Meridian\Policy\Models\CircuitBreaker;
use App\Domain\Meridian\Policy\Models\ProxyMetric;
use Illuminate\Support\Facades\DB;

class IssuanceService
{
    /**
     * Minimum number of independent (distinct-rotation-group) verifier
     * signatures for a contribution mint (I4's M).
     */
    public const QUORUM_M = 3;

    /**
     * Payload markers that reveal raw measurements rather than
     * commitments (I8 fail-closed screen; the DB event trigger and the
     * CI migration scan are the other two layers).
     */
    private const RAW_MEASUREMENT_MARKERS = [
        'raw:', 'measurement:', 'biometric', 'fingerprint', 'retina',
        'iris', 'face_geometry', 'voiceprint', 'gait', 'dna', 'genome',
        'health', 'diagnosis', 'medical', 'blood', 'heart_rate',
        'neural', 'eeg', 'brainwave',
    ];

    public function __construct(private readonly LedgerService $ledger)
    {
    }

    /**
     * Evaluate the Core-Riba predicate (I11) and the family rules,
     * refusing non-conforming policies, then create the currency, its
     * system accounts, and the policy row.
     */
    public function instantiateCurrency(CurrencyPolicy $policy): Currency
    {
        // I8: neural-sourced currencies are refused outright.
        if ($policy->neuralSourced) {
            throw new SensitiveDataException(
                'I8: a currency sourced from neural/biometric measurement may never be instantiated.'
            );
        }

        // I11: the four-element test. A squarely-Core-Riba policy is
        // refused — no presumption can rescue it (DOCUMENT 2.1 §6.3).
        if ($policy->isSquarelyCoreRiba()) {
            throw new CoreRibaPolicyException(
                'I11: this policy encodes all four Core-Riba elements simultaneously '
                .'(money-like base, prefixed guaranteed increase, no risk borne, no value created, '
                .'extraction from the counterparty) and is refused. There is no third path.'
            );
        }

        // DOCUMENT 2.1 §6.3 genuine-risk check: a policy whose ONLY
        // escape from the Core-Riba conjunction is its risk_bearing flag
        // must demonstrate a non-degenerate loss distribution.
        $escapesOnlyViaRiskFlag = $policy->baseKind->isRibaEligibleBase()
            && $policy->increaseKind === IncreaseKind::PrefixedGuaranteed
            && ! $policy->valueCreating
            && $policy->extractsFromCounterparty
            && $policy->riskBearing;

        if ($escapesOnlyViaRiskFlag && ! $policy->hasGenuineRiskBearing()) {
            throw new CoreRibaPolicyException(
                'I11: the policy claims risk-bearing but exposes no non-degenerate loss '
                .'distribution (Var(return) > 0 with real downside); the claim does not '
                .'rescue an otherwise squarely-Core-Riba construction.'
            );
        }

        return DB::transaction(function () use ($policy): Currency {
            $currency = new Currency([
                'code' => $policy->code,
                'name' => $policy->name,
                'family' => $policy->family,
                'decimals' => $policy->decimals,
            ]);
            $currency->save();

            foreach (SystemAccountRole::cases() as $role) {
                $account = new Account([
                    'owner_type' => 'system',
                    'currency_id' => $currency->id,
                    'type' => AccountType::System,
                    'system_role' => $role,
                ]);
                $account->save();
            }

            $row = new IssuancePolicy([
                'currency_id' => $currency->id,
                'type' => $policy->type,
                'params' => $policy->params,
                'max_supply' => $policy->maxSupply,
                'base_kind' => $policy->baseKind,
                'increase_kind' => $policy->increaseKind,
                'risk_bearing' => $policy->riskBearing,
                'value_creating' => $policy->valueCreating,
                'extracts_from_counterparty' => $policy->extractsFromCounterparty,
            ]);
            $row->save();

            return $currency;
        });
    }

    /**
     * Mint against attested custody; refuse any mint that would push the
     * outstanding supply beyond the attested reserve.
     */
    public function mintReserve(Currency $currency, ReserveDeposit $deposit): LedgerTransaction
    {
        $this->assertPolicyType($currency, IssuanceType::Reserve1To1);
        $this->assertIssuanceOpen($currency);

        $issuance = $this->systemAccount($currency, SystemAccountRole::Issuance);

        // Outstanding supply = -ISSUANCE balance (issuance carries the
        // negative mirror of everything minted, net of burns).
        $outstanding = -1 * $issuance->refresh()->balance_minor;

        if ($outstanding + $deposit->amountMinor > $deposit->attestedReserveMinor) {
            throw new ReserveExceededException(
                "Reserve discipline: outstanding supply {$outstanding} + mint {$deposit->amountMinor} "
                ."would exceed attested reserve {$deposit->attestedReserveMinor} "
                ."(attestation {$deposit->custodyAttestationRef}). Refused."
            );
        }

        return $this->ledger->post(new TransactionDraft(
            kind: TransactionKind::Mint,
            entries: [
                new EntryDraft($issuance->id, $currency->id, -$deposit->amountMinor),
                new EntryDraft($deposit->recipientAccountId, $currency->id, $deposit->amountMinor),
            ],
            idempotencyKey: $deposit->idempotencyKey,
            metadata: [
                'issuance_model' => IssuanceType::Reserve1To1->value,
                'custody_attestation_ref' => $deposit->custodyAttestationRef,
            ],
        ));
    }

    /**
     * Burn reserve-backed units on holder-authorized redemption (I10:
     * the debit side carries the holder's authorization).
     */
    public function burnReserve(Currency $currency, Redemption $redemption): LedgerTransaction
    {
        $this->assertPolicyType($currency, IssuanceType::Reserve1To1);

        $issuance = $this->systemAccount($currency, SystemAccountRole::Issuance);

        return $this->ledger->post(new TransactionDraft(
            kind: TransactionKind::Burn,
            entries: [
                new EntryDraft(
                    $redemption->holderAccountId,
                    $currency->id,
                    -$redemption->amountMinor,
                    holderAuthorizationRef: $redemption->holderAuthorizationRef,
                ),
                new EntryDraft($issuance->id, $currency->id, $redemption->amountMinor),
            ],
            idempotencyKey: $redemption->idempotencyKey,
            metadata: ['issuance_model' => IssuanceType::Reserve1To1->value],
        ));
    }

    /**
     * Mint a 1:1 representation ONLY against a confirmed source-chain
     * lock. No confirmed lock, no mint.
     */
    public function representBridged(Currency $currency, BridgeLock $lock): LedgerTransaction
    {
        $this->assertPolicyType($currency, IssuanceType::Bridge);
        $this->assertIssuanceOpen($currency);

        if (! $lock->lockConfirmed) {
            throw new ReserveExceededException(
                "Bridge discipline: source-chain lock {$lock->sourceLockRef} on {$lock->sourceChain} "
                .'is not confirmed; a 1:1 representation may not be minted.'
            );
        }

        $issuance = $this->systemAccount($currency, SystemAccountRole::Issuance);

        return $this->ledger->post(new TransactionDraft(
            kind: TransactionKind::Mint,
            entries: [
                new EntryDraft($issuance->id, $currency->id, -$lock->amountMinor),
                new EntryDraft($lock->recipientAccountId, $currency->id, $lock->amountMinor),
            ],
            idempotencyKey: $lock->idempotencyKey,
            metadata: [
                'issuance_model' => IssuanceType::Bridge->value,
                'source_chain' => $lock->sourceChain,
                'source_lock_ref' => $lock->sourceLockRef,
            ],
        ));
    }

    /**
     * Mint contribution credits ONLY on a valid quorum attestation (I4):
     * every signature verified against a registered active verifier's
     * pubkey; ≥ QUORUM_M signers from DISTINCT rotation groups; unexpired;
     * nonce consumed atomically with the mint.
     */
    public function mintContribution(Attestation $attestation): LedgerTransaction
    {
        // I8 service screen: only commitments / minimized-disclosure
        // proofs may ride in subject_proof — never raw measurements.
        $this->assertNoRawSensitivePayload($attestation->subject_proof);

        $throttledCurrency = Currency::query()->findOrFail($attestation->currency_id);
        $this->assertIssuanceOpen($throttledCurrency);
        $this->assertWithinThrottledEpochCap($throttledCurrency, $attestation->amount_minor);

        if ($attestation->minted_transaction_id !== null) {
            throw new QuorumNotMetException(
                "I4: attestation nonce {$attestation->nonce} was already consumed; replay rejected."
            );
        }

        if ($attestation->expires_at->isPast()) {
            throw new QuorumNotMetException(
                "I4: attestation nonce {$attestation->nonce} expired at {$attestation->expires_at->toIso8601String()}."
            );
        }

        // Verify each signature against its registered verifier and count
        // DISTINCT rotation groups (M independent parties, not M keys).
        $payload = $attestation->signablePayload();
        $independentGroups = [];

        foreach ($attestation->signatures as $sig) {
            $verifier = Verifier::query()
                ->where('id', $sig['verifier_id'])
                ->where('status', 'active')
                ->first();

            if ($verifier === null) {
                continue; // Unregistered/suspended signer: does not count.
            }

            $publicKey = base64_decode($verifier->pubkey, true);
            $signature = base64_decode($sig['signature'], true);

            if ($publicKey === false || $signature === false
                || strlen($publicKey) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES
                || strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES) {
                continue; // Malformed material: does not count.
            }

            if (! sodium_crypto_sign_verify_detached($signature, $payload, $publicKey)) {
                continue; // Forged signature: does not count.
            }

            $independentGroups[$verifier->rotation_group] = true;
        }

        if (count($independentGroups) < self::QUORUM_M) {
            throw new QuorumNotMetException(
                'I4: quorum not met — '.count($independentGroups)
                .' independent rotation group(s) verified, '.self::QUORUM_M.' required. '
                .'Sub-quorum, forged, unregistered, and same-rotation-group signatures do not count.'
            );
        }

        $currency = Currency::query()->findOrFail($attestation->currency_id);
        $issuance = $this->systemAccount($currency, SystemAccountRole::Issuance);

        return DB::transaction(function () use ($attestation, $currency, $issuance): LedgerTransaction {
            $transaction = $this->ledger->post(new TransactionDraft(
                kind: TransactionKind::Mint,
                entries: [
                    new EntryDraft($issuance->id, $currency->id, -$attestation->amount_minor),
                    new EntryDraft($attestation->recipient_account_id, $currency->id, $attestation->amount_minor),
                ],
                idempotencyKey: 'povc:'.$attestation->nonce,
                metadata: [
                    'issuance_model' => IssuanceType::Povc->value,
                    'attestation_id' => $attestation->id,
                    'attestation_nonce' => $attestation->nonce,
                ],
            ));

            // Consume the nonce atomically with the mint; the DB trigger
            // (attestations_guard_mint) re-checks quorum/expiry/replay.
            $attestation->quorum_met = true;
            $attestation->minted_transaction_id = $transaction->id;
            $attestation->status = 'minted';
            $attestation->save();

            return $transaction;
        });
    }

    private function assertPolicyType(Currency $currency, IssuanceType $expected): void
    {
        $policy = IssuancePolicy::query()
            ->where('currency_id', $currency->id)
            ->firstOrFail();

        if ($policy->type !== $expected) {
            throw new \DomainException(
                "Issuance model mismatch: currency {$currency->code} is {$policy->type->value}, "
                ."operation requires {$expected->value}."
            );
        }
    }

    private function systemAccount(Currency $currency, SystemAccountRole $role): Account
    {
        return Account::query()
            ->where('currency_id', $currency->id)
            ->where('system_role', $role->value)
            ->firstOrFail();
    }

    /**
     * The Policy Engine's protective halt (DEV-4.5): a fired circuit
     * breaker halts AUTOMATIC issuance for the currency. A halt is a
     * negative power — it refuses new mints, it touches no balance.
     */
    private function assertIssuanceOpen(Currency $currency): void
    {
        $fired = CircuitBreaker::query()
            ->where('currency_id', $currency->id)
            ->where('status', 'fired')
            ->exists();

        if ($fired) {
            throw new \DomainException(
                "Circuit breaker fired for currency {$currency->code}: automatic "
                .'issuance is halted. Existing balances are untouched.'
            );
        }
    }

    /**
     * The anti-Goodhart throttle entering the mint cap (DOCUMENT 2.3 §3):
     * effective_cap = base_epoch_cap × epoch_cap_multiplier × θ. The θ
     * term multiplies FUTURE mint only; it appears in no computation
     * over existing balances.
     */
    private function assertWithinThrottledEpochCap(Currency $currency, int $amountMinor): void
    {
        $policy = IssuancePolicy::query()
            ->where('currency_id', $currency->id)
            ->first();

        $rateLimit = $policy?->rate_limit;
        $baseCap = is_numeric($rateLimit['epoch_mint_cap_minor'] ?? null)
            ? (int) $rateLimit['epoch_mint_cap_minor']
            : null;

        if ($baseCap === null) {
            return; // No epoch cap configured for this currency.
        }

        $multiplier = is_numeric($rateLimit['epoch_cap_multiplier'] ?? null)
            ? (float) $rateLimit['epoch_cap_multiplier']
            : 1.0;

        $theta = 1.0;
        $metric = ProxyMetric::query()->where('currency_id', $currency->id)->first();
        if ($metric !== null) {
            $theta = (float) $metric->throttle_value;
        }

        $effectiveCap = (int) floor($baseCap * $multiplier * $theta);

        $mintedThisEpoch = (int) DB::table('entries')
            ->join('transactions', 'transactions.id', '=', 'entries.transaction_id')
            ->join('accounts', 'accounts.id', '=', 'entries.account_id')
            ->where('transactions.kind', 'mint')
            ->where('entries.currency_id', $currency->id)
            ->where('entries.amount', '>', 0)
            ->where('accounts.owner_type', 'person')
            ->where('transactions.posted_at', '>=', now()->startOfDay())
            ->sum('entries.amount');

        if ($mintedThisEpoch + $amountMinor > $effectiveCap) {
            throw new \DomainException(
                "Anti-Goodhart throttle: minting {$amountMinor} would exceed the "
                ."throttled epoch cap {$effectiveCap} (base {$baseCap} × multiplier "
                ."{$multiplier} × θ {$theta}; {$mintedThisEpoch} already minted this "
                .'epoch). The response to gaming is to mint fewer NEW credits — '
                .'existing balances are never touched.'
            );
        }
    }

    private function assertNoRawSensitivePayload(string $subjectProof): void
    {
        $lower = strtolower($subjectProof);

        foreach (self::RAW_MEASUREMENT_MARKERS as $marker) {
            if (str_contains($lower, $marker)) {
                throw new SensitiveDataException(
                    'I8: attestation subject_proof appears to carry a raw '
                    .'biometric/health/neural measurement; only ZK commitments or '
                    .'minimized-disclosure proofs are accepted.'
                );
            }
        }
    }
}
