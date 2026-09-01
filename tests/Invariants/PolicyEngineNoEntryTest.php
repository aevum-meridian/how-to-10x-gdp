<?php

// SPDX-License-Identifier: LicenseRef-MVL-2.0

declare(strict_types=1);

namespace Tests\Invariants;

use App\Domain\Meridian\Issuance\Data\CurrencyPolicy;
use App\Domain\Meridian\Issuance\Data\ReserveDeposit;
use App\Domain\Meridian\Issuance\Enums\BaseKind;
use App\Domain\Meridian\Issuance\Enums\IncreaseKind;
use App\Domain\Meridian\Issuance\Enums\IssuanceType;
use App\Domain\Meridian\Issuance\Models\Attestation;
use App\Domain\Meridian\Issuance\Models\Verifier;
use App\Domain\Meridian\Issuance\Services\IssuanceService;
use App\Domain\Meridian\Ledger\Enums\CurrencyFamily;
use App\Domain\Meridian\Ledger\Models\Account;
use App\Domain\Meridian\Ledger\Models\Currency;
use App\Domain\Meridian\Policy\Data\PolicyDelta;
use App\Domain\Meridian\Policy\Enums\BreakerReason;
use App\Domain\Meridian\Policy\Models\ProxyMetric;
use App\Domain\Meridian\Policy\Services\PolicyEngineService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Finder\Finder;
use Tests\Support\LedgerFixtures;
use Tests\TestCase;

/**
 * PolicyEngineNoEntryTest — Invariant I7 (Issuance-Only Macro Control).
 *
 * DOCUMENT 0.1 I7 / DOCUMENT 4.5: across ALL Policy Engine action types
 * and ALL inputs, no action ever produces an entry against a personal
 * account; the module cannot even import the ledger-write path; and the
 * meridian_policy_engine DB role independently lacks the privilege.
 * The heart shapes the faucet, never the reservoir.
 */
final class PolicyEngineNoEntryTest extends TestCase
{
    private PolicyEngineService $policy;

    private IssuanceService $issuance;

    protected function setUp(): void
    {
        parent::setUp();
        $this->policy = app(PolicyEngineService::class);
        $this->issuance = app(IssuanceService::class);
    }

    // ------------------------------------------------------------------
    // Architectural: the typed boundary — the Policy module cannot
    // reference the ledger-write path at all.
    // ------------------------------------------------------------------

    public function test_the_policy_module_never_references_the_ledger_write_path(): void
    {
        $forbidden = [
            'LedgerService',
            'DisputeService',
            'EntryDraft',
            'TransactionDraft',
            '->post(',
            '->persist(',
            'INSERT INTO entries',
            'INSERT INTO transactions',
        ];

        $files = iterator_to_array(
            (new Finder())->files()->in(app_path('Domain/Meridian/Policy'))->name('*.php')
        );
        $this->assertNotEmpty($files);

        foreach ($files as $file) {
            $source = $file->getContents();
            foreach ($forbidden as $token) {
                $this->assertFalse(
                    str_contains($source, $token),
                    "I7 violated: {$file->getRelativePathname()} references the ledger-write path via \"{$token}\"."
                );
            }
        }
    }

    public function test_the_policy_engine_db_role_cannot_write_the_ledger(): void
    {
        foreach (['entries', 'transactions'] as $table) {
            foreach (['INSERT', 'UPDATE', 'DELETE', 'TRUNCATE'] as $priv) {
                /** @var object{ok: bool} $allowed */
                $allowed = DB::selectOne(
                    'SELECT has_table_privilege(?, ?, ?) AS ok',
                    ['meridian_policy_engine', $table, $priv]
                );
                $this->assertFalse(
                    (bool) $allowed->ok,
                    "I7 privilege model: meridian_policy_engine must not hold {$priv} on {$table}."
                );
            }
        }

        // Its permitted writes are exactly its own tables + issuance_policies.
        foreach (['proxy_metrics', 'policy_actions', 'circuit_breakers', 'issuance_policies'] as $table) {
            /** @var object{ok: bool} $allowed */
            $allowed = DB::selectOne(
                'SELECT has_table_privilege(?, ?, ?) AS ok',
                ['meridian_policy_engine', $table, 'INSERT']
            );
            $this->assertTrue((bool) $allowed->ok, "meridian_policy_engine must be able to write {$table} (future minting only).");
        }
    }

    // ------------------------------------------------------------------
    // Runtime: every action type, zero entries.
    // ------------------------------------------------------------------

    public function test_no_policy_action_of_any_type_ever_produces_a_ledger_entry(): void
    {
        $currency = $this->povcCurrency();
        $holder = LedgerFixtures::personalAccount($currency);
        LedgerFixtures::mint($holder, 75_000);

        $metric = new ProxyMetric([
            'currency_id' => $currency->id,
            'declared_virtue' => 'verified community care work',
            'measured_proxy' => '100.0',
            'independent_signal' => '40.0', // severe gaming: D = 60 > 2·D*
            'threshold' => '20.0',
        ]);
        $metric->save();

        $entriesBefore = (int) DB::table('entries')->count();
        $txnsBefore = (int) DB::table('transactions')->count();
        $balanceBefore = $holder->refresh()->balance_minor;

        // Every action type in the inventory:
        $this->policy->observe();
        $this->policy->adjustIssuancePolicy($currency, new PolicyDelta(
            rateLimitMultiplier: 0.8,
            newMaxSupply: 900_000_000,
            justification: 'Tighten future issuance for the epoch.',
        ));
        $decision = $this->policy->evaluateProxyDivergence($currency); // also fires breaker (spike)
        $this->policy->fireCircuitBreaker($currency, BreakerReason::AnomalousInput);

        // Severe gaming produced a throttle and a halt — and NOT ONE entry.
        $this->assertLessThan(1.0, $decision->theta);
        $this->assertSame($entriesBefore, (int) DB::table('entries')->count(), 'I7 violated: a policy action produced a ledger entry.');
        $this->assertSame($txnsBefore, (int) DB::table('transactions')->count(), 'I7 violated: a policy action produced a transaction.');
        $this->assertSame($balanceBefore, $holder->refresh()->balance_minor, 'I7 violated: a policy action moved a personal balance.');
    }

    public function test_a_delta_beyond_the_per_epoch_movement_cap_is_refused(): void
    {
        $currency = $this->povcCurrency();

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('movement cap');
        $this->policy->adjustIssuancePolicy($currency, new PolicyDelta(
            rateLimitMultiplier: 0.5, // |0.5 - 1.0| = 0.5 > 0.25 cap
            newMaxSupply: null,
            justification: 'Corrupted-oracle overreaction.',
        ));
    }

    // ------------------------------------------------------------------
    // The θ guarantee (DOCUMENT 2.3 §3): future mint only.
    // ------------------------------------------------------------------

    public function test_the_throttle_multiplies_future_mint_only_and_never_touches_existing_balances(): void
    {
        $currency = $this->povcCurrency(epochMintCapMinor: 100_000);
        $signers = $this->registerVerifiers();
        $holder = LedgerFixtures::personalAccount($currency);

        // Pre-throttle: an 80_000 contribution mint fits the 100_000 cap.
        $this->quorumMint($currency, $holder, 80_000, $signers);
        $this->assertSame(80_000, $holder->refresh()->balance_minor);

        // Goodhart drift detected: D = 30 vs D* = 20 → θ = 0.5.
        $metric = new ProxyMetric([
            'currency_id' => $currency->id,
            'declared_virtue' => 'verified community care work',
            'measured_proxy' => '90.0',
            'independent_signal' => '60.0',
            'threshold' => '20.0',
        ]);
        $metric->save();
        $decision = $this->policy->evaluateProxyDivergence($currency);
        $this->assertSame(0.5, $decision->theta);

        // Future mint cap is now 100_000 × 0.5 = 50_000; 80_000 already
        // minted this epoch → any further mint is refused.
        try {
            $this->quorumMint($currency, $holder, 10_000, $signers);
            $this->fail('The throttled epoch cap did not bind future minting.');
        } catch (\DomainException $e) {
            $this->assertStringContainsString('throttle', strtolower($e->getMessage()));
        }

        // And the existing balance is untouched — θ appears in no term
        // affecting the stock of credits.
        $this->assertSame(80_000, $holder->refresh()->balance_minor);
    }

    public function test_a_fired_circuit_breaker_halts_issuance_but_touches_no_balance(): void
    {
        $policy = new CurrencyPolicy(
            code: 'RSV'.strtoupper(Str::random(8)),
            name: 'Reserve Test',
            family: CurrencyFamily::Reserve,
            decimals: 2,
            type: IssuanceType::Reserve1To1,
            baseKind: BaseKind::RealAsset,
            increaseKind: IncreaseKind::None,
            riskBearing: true,
            valueCreating: true,
            extractsFromCounterparty: false,
            declaredLossDistribution: [-0.1, 0.05],
        );
        $currency = $this->issuance->instantiateCurrency($policy);
        $holder = LedgerFixtures::personalAccount($currency);

        $this->issuance->mintReserve($currency, new ReserveDeposit(
            recipientAccountId: $holder->id,
            amountMinor: 40_000,
            attestedReserveMinor: 1_000_000,
            custodyAttestationRef: 'custody:'.Str::ulid(),
            idempotencyKey: 'reserve-mint-'.Str::ulid(),
        ));
        $this->assertSame(40_000, $holder->refresh()->balance_minor);

        $this->policy->fireCircuitBreaker($currency, BreakerReason::ReserveShortfall);

        try {
            $this->issuance->mintReserve($currency, new ReserveDeposit(
                recipientAccountId: $holder->id,
                amountMinor: 10_000,
                attestedReserveMinor: 1_000_000,
                custodyAttestationRef: 'custody:'.Str::ulid(),
                idempotencyKey: 'reserve-mint-'.Str::ulid(),
            ));
            $this->fail('A fired circuit breaker did not halt issuance.');
        } catch (\DomainException $e) {
            $this->assertStringContainsString('Circuit breaker', $e->getMessage());
        }

        $this->assertSame(40_000, $holder->refresh()->balance_minor);
    }

    // ------------------------------------------------------------------
    // Helpers.
    // ------------------------------------------------------------------

    private function povcCurrency(?int $epochMintCapMinor = null): Currency
    {
        $currency = $this->issuance->instantiateCurrency(new CurrencyPolicy(
            code: 'PVC'.strtoupper(Str::random(8)),
            name: 'PoVC Test',
            family: CurrencyFamily::Contribution,
            decimals: 2,
            type: IssuanceType::Povc,
            baseKind: BaseKind::Contribution,
            increaseKind: IncreaseKind::None,
            riskBearing: false,
            valueCreating: true,
            extractsFromCounterparty: false,
            maxSupply: 1_000_000_000,
        ));

        if ($epochMintCapMinor !== null) {
            DB::table('issuance_policies')
                ->where('currency_id', $currency->id)
                ->update(['rate_limit' => json_encode(['epoch_mint_cap_minor' => $epochMintCapMinor], JSON_THROW_ON_ERROR)]);
        }

        return $currency;
    }

    /** @return list<array{verifier: Verifier, secret: string}> */
    private function registerVerifiers(): array
    {
        $signers = [];
        for ($i = 0; $i < 3; $i++) {
            $pair = sodium_crypto_sign_keypair();
            $verifier = new Verifier([
                'name' => "policy-test-verifier-{$i}",
                'pubkey' => base64_encode(sodium_crypto_sign_publickey($pair)),
                'family_scope' => 'contribution',
                'status' => 'active',
                'rotation_group' => "policy-group-{$i}",
                'bond' => 1_000_000,
            ]);
            $verifier->save();
            $signers[] = ['verifier' => $verifier, 'secret' => sodium_crypto_sign_secretkey($pair)];
        }

        return $signers;
    }

    /** @param list<array{verifier: Verifier, secret: string}> $signers */
    private function quorumMint(Currency $currency, Account $recipient, int $amountMinor, array $signers): void
    {
        $attestation = new Attestation([
            'currency_id' => $currency->id,
            'recipient_account_id' => $recipient->id,
            'subject_proof' => 'zkc:'.Str::random(16),
            'amount_minor' => $amountMinor,
            'nonce' => 'nonce-'.Str::ulid(),
            'expires_at' => now()->addHour(),
        ]);
        $attestation->save();

        $payload = $attestation->signablePayload();
        $attestation->signatures = array_map(
            static fn (array $s): array => [
                'verifier_id' => $s['verifier']->id,
                'signature' => base64_encode(sodium_crypto_sign_detached($payload, $s['secret'])),
            ],
            $signers,
        );
        $attestation->save();

        $this->issuance->mintContribution($attestation->refresh());
    }
}
