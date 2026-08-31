<?php

// SPDX-License-Identifier: LicenseRef-MVL-2.0

declare(strict_types=1);

namespace Tests\Invariants;

use App\Domain\Meridian\Issuance\Data\CurrencyPolicy;
use App\Domain\Meridian\Issuance\Enums\BaseKind;
use App\Domain\Meridian\Issuance\Enums\IncreaseKind;
use App\Domain\Meridian\Issuance\Enums\IssuanceType;
use App\Domain\Meridian\Issuance\Exceptions\ReserveExceededException;
use App\Domain\Meridian\Issuance\Services\IssuanceService;
use App\Domain\Meridian\Ledger\Enums\CurrencyFamily;
use App\Domain\Meridian\Ledger\Models\Currency;
use App\Domain\Meridian\Policy\Enums\BreakerReason;
use App\Domain\Meridian\Policy\Models\CircuitBreaker;
use App\Domain\Meridian\Reserve\Data\StructuredPostalAddress;
use App\Domain\Meridian\Reserve\Exceptions\AttestationRejectedException;
use App\Domain\Meridian\Reserve\Exceptions\StaleAttestationException;
use App\Domain\Meridian\Reserve\Models\ReserveAttestation;
use App\Domain\Meridian\Reserve\Models\ReserveCustodian;
use App\Domain\Meridian\Reserve\Services\Iso20022AddressPolicy;
use App\Domain\Meridian\Reserve\Services\ReserveAttestationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\LedgerFixtures;
use Tests\TestCase;

/**
 * ReserveAttestationTest — DOCUMENT 8.3 (Reserve Attestation &
 * Proof-of-Backing), enforcing I3 for reserve-backed currencies and the
 * user's right to proof-of-backing.
 *
 * Layers proven:
 *  - service guards (signature, replay, revocation, freshness, fail-closed
 *    absence of attestation)
 *  - DB constraints and triggers (unique nonce, append-only attestations,
 *    non-negative figures)
 *  - the structural refusal of a mint beyond attested reserves (DEV-4.2's
 *    guard fed by DEV-8.3's real record)
 *  - the AUTOMATIC crisis trigger on an attested shortfall (§4)
 *  - the membrane's ISO 20022 receive-tolerant / send-strict posture (§3)
 */
final class ReserveAttestationTest extends TestCase
{
    private ReserveAttestationService $reserve;

    private IssuanceService $issuance;

    protected function setUp(): void
    {
        parent::setUp();
        $this->reserve = app(ReserveAttestationService::class);
        $this->issuance = app(IssuanceService::class);
    }

    // ------------------------------------------------------------------
    // The attestation record: signed, replay-bounded, append-only.
    // ------------------------------------------------------------------

    public function test_a_valid_signed_attestation_is_accepted_and_independently_verifiable(): void
    {
        [$currency, $custodian, $secret] = $this->custodiedCurrency();

        $attestation = $this->signedIngest($custodian, $secret, 1_000_000);

        $this->assertSame(1_000_000, $attestation->attested_reserve_minor);

        // The user's right to proof-of-backing: the latest attestation is
        // exposed and verifies against the registered custodian key.
        $latest = $this->reserve->latestFor($currency);
        $this->assertNotNull($latest);
        $this->assertSame($attestation->id, $latest->id);
        $this->assertTrue($this->reserve->verify($latest));
    }

    public function test_forged_or_mismatched_signatures_are_refused_and_nothing_is_stored(): void
    {
        [, $custodian, $secret] = $this->custodiedCurrency();

        // A signature by a different key.
        $foreign = sodium_crypto_sign_keypair();
        $attestedAt = new \DateTimeImmutable('now');
        $nonce = 'nonce-'.Str::ulid();
        $message = ReserveAttestationService::attestationMessage(
            $custodian->id,
            $custodian->currency_id,
            500_000,
            $nonce,
            $attestedAt,
        );
        $forged = bin2hex(sodium_crypto_sign_detached($message, sodium_crypto_sign_secretkey($foreign)));

        try {
            $this->reserve->ingest($custodian, 500_000, $nonce, $forged, $attestedAt);
            $this->fail('A forged attestation was accepted.');
        } catch (AttestationRejectedException $e) {
            $this->assertStringContainsString('does not verify', $e->getMessage());
        }

        // A genuine signature over a DIFFERENT figure than the one claimed.
        $honest = bin2hex(sodium_crypto_sign_detached(
            ReserveAttestationService::attestationMessage(
                $custodian->id,
                $custodian->currency_id,
                100,
                'nonce-'.Str::ulid(),
                $attestedAt,
            ),
            $secret,
        ));

        try {
            $this->reserve->ingest($custodian, 999_999_999, 'nonce-'.Str::ulid(), $honest, $attestedAt);
            $this->fail('An attestation whose signature covers a different figure was accepted.');
        } catch (AttestationRejectedException) {
            // The figure is bound into the signed message: inflating it
            // after signing breaks verification.
        }

        $this->assertSame(0, ReserveAttestation::query()->count());
    }

    public function test_replayed_nonces_are_refused_at_both_layers_and_revoked_custodians_cannot_attest(): void
    {
        [, $custodian, $secret] = $this->custodiedCurrency();

        $attestedAt = new \DateTimeImmutable('now');
        $nonce = 'nonce-'.Str::ulid();
        $this->signedIngest($custodian, $secret, 700_000, $nonce, $attestedAt);

        // Service layer: same nonce, fresh signature — refused.
        try {
            $this->signedIngest($custodian, $secret, 700_000, $nonce, $attestedAt->modify('+1 minute'));
            $this->fail('A replayed attestation nonce was accepted.');
        } catch (AttestationRejectedException $e) {
            $this->assertStringContainsString('replayed', $e->getMessage());
        }

        // DB layer: a direct insert with the same nonce violates the
        // unique constraint independently of the service.
        try {
            DB::table('reserve_attestations')->insert([
                'id' => (string) Str::ulid(),
                'custodian_id' => $custodian->id,
                'currency_id' => $custodian->currency_id,
                'attested_reserve_minor' => 1,
                'nonce' => $nonce,
                'signature' => 'raw',
                'attested_at' => now(),
            ]);
            $this->fail('The database accepted a duplicate attestation nonce.');
        } catch (\Illuminate\Database\QueryException $e) {
            $this->assertStringContainsString('unique', strtolower($e->getMessage()));
        }

        // Revocation closes the custodian's mouth.
        $custodian->revoked_at = now();
        $custodian->save();

        try {
            $this->signedIngest($custodian, $secret, 800_000);
            $this->fail('A revoked custodian attested reserves.');
        } catch (AttestationRejectedException $e) {
            $this->assertStringContainsString('revoked', $e->getMessage());
        }
    }

    public function test_attestations_are_append_only_at_the_database(): void
    {
        [, $custodian, $secret] = $this->custodiedCurrency();
        $attestation = $this->signedIngest($custodian, $secret, 300_000);

        try {
            DB::table('reserve_attestations')
                ->where('id', $attestation->id)
                ->update(['attested_reserve_minor' => 999_999_999]);
            $this->fail('An attestation record was rewritten.');
        } catch (\Illuminate\Database\QueryException $e) {
            $this->assertStringContainsString('append-only', $e->getMessage());
        }

        try {
            DB::table('reserve_attestations')->where('id', $attestation->id)->delete();
            $this->fail('An attestation record was deleted.');
        } catch (\Illuminate\Database\QueryException $e) {
            $this->assertStringContainsString('append-only', $e->getMessage());
        }

        $this->assertSame(
            300_000,
            (int) DB::table('reserve_attestations')->where('id', $attestation->id)->value('attested_reserve_minor'),
        );
    }

    // ------------------------------------------------------------------
    // Structural refusal: a mint beyond attested reserves is impossible.
    // ------------------------------------------------------------------

    public function test_minting_beyond_the_attested_reserve_is_structurally_refused(): void
    {
        [$currency, $custodian, $secret] = $this->custodiedCurrency();
        $holder = LedgerFixtures::personalAccount($currency);

        $this->signedIngest($custodian, $secret, 100_000);

        // Within the attested figure: fine.
        $this->issuance->mintReserve($currency, $this->reserve->buildReserveDeposit(
            $currency,
            $holder->id,
            60_000,
            'rsv-mint-'.Str::ulid(),
        ));
        $this->assertSame(60_000, $holder->refresh()->balance_minor);

        // Beyond it: refused, balances untouched.
        try {
            $this->issuance->mintReserve($currency, $this->reserve->buildReserveDeposit(
                $currency,
                $holder->id,
                40_001,
                'rsv-mint-'.Str::ulid(),
            ));
            $this->fail('A mint beyond the attested reserve posted.');
        } catch (ReserveExceededException $e) {
            $this->assertStringContainsString('would exceed attested reserve', $e->getMessage());
        }

        $this->assertSame(60_000, $holder->refresh()->balance_minor);
    }

    public function test_without_a_fresh_attestation_there_is_nothing_to_mint_against(): void
    {
        [$currency, $custodian] = $this->custodiedCurrency();
        $holder = LedgerFixtures::personalAccount($currency);

        // No attestation at all: fail CLOSED.
        try {
            $this->reserve->buildReserveDeposit($currency, $holder->id, 1_000, 'rsv-'.Str::ulid());
            $this->fail('A deposit was fabricated without any attestation.');
        } catch (StaleAttestationException $e) {
            $this->assertStringContainsString('No reserve attestation exists', $e->getMessage());
        }

        // A stale attestation (seeded directly — inserts are lawful, only
        // rewrites are not) cannot back a new mint either.
        DB::table('reserve_attestations')->insert([
            'id' => (string) Str::ulid(),
            'custodian_id' => $custodian->id,
            'currency_id' => $currency->id,
            'attested_reserve_minor' => 1_000_000,
            'nonce' => 'nonce-'.Str::ulid(),
            'signature' => 'seeded-stale',
            'attested_at' => now()->subDays(3),
        ]);

        try {
            $this->reserve->buildReserveDeposit($currency, $holder->id, 1_000, 'rsv-'.Str::ulid());
            $this->fail('A stale attestation backed a new mint.');
        } catch (StaleAttestationException $e) {
            $this->assertStringContainsString('freshness floor', $e->getMessage());
        }
    }

    // ------------------------------------------------------------------
    // §4 — the automatic crisis trigger on an attested shortfall.
    // ------------------------------------------------------------------

    public function test_an_attested_shortfall_fires_the_circuit_breaker_automatically_and_touches_no_balance(): void
    {
        [$currency, $custodian, $secret] = $this->custodiedCurrency();
        $holder = LedgerFixtures::personalAccount($currency);

        $this->signedIngest($custodian, $secret, 100_000);
        $this->issuance->mintReserve($currency, $this->reserve->buildReserveDeposit(
            $currency,
            $holder->id,
            80_000,
            'rsv-mint-'.Str::ulid(),
        ));
        $this->assertSame(80_000, $holder->refresh()->balance_minor);

        // The custodian's next attestation reveals reserves BELOW the
        // 80,000 outstanding. The breaker fires inside ingest() itself —
        // no human gets to decide whether to disclose.
        $this->signedIngest($custodian, $secret, 50_000);

        $fired = CircuitBreaker::query()
            ->where('currency_id', $currency->id)
            ->where('status', 'fired')
            ->first();
        $this->assertNotNull($fired);
        $this->assertSame(BreakerReason::ReserveShortfall, $fired->reason);

        // Automatic issuance is halted...
        try {
            $this->issuance->mintReserve($currency, $this->reserve->buildReserveDeposit(
                $currency,
                $holder->id,
                1_000,
                'rsv-mint-'.Str::ulid(),
            ));
            $this->fail('Issuance continued through an attested shortfall.');
        } catch (\DomainException $e) {
            $this->assertStringContainsString('Circuit breaker', $e->getMessage());
        }

        // ...and every existing balance is untouched: the halt is a
        // negative, protective power.
        $this->assertSame(80_000, $holder->refresh()->balance_minor);

        // A healthy attestation does not fire the breaker (control case,
        // on a second currency).
        [$currency2, $custodian2, $secret2] = $this->custodiedCurrency();
        $this->signedIngest($custodian2, $secret2, 1_000_000);
        $this->assertSame(0, CircuitBreaker::query()->where('currency_id', $currency2->id)->count());
    }

    // ------------------------------------------------------------------
    // §3 — ISO 20022: receive-tolerant, send-strict.
    // ------------------------------------------------------------------

    public function test_iso20022_receives_tolerantly_and_sends_strictly(): void
    {
        $policy = new Iso20022AddressPolicy();

        // RECEIVE-TOLERANT: a hybrid inbound address is normalized, not
        // rejected.
        $normalized = $policy->normalizeInbound([
            'country' => 'de',
            'post_code' => '10115',
            'address_lines' => ['Invalidenstrasse 44', 'Berlin'],
        ]);
        $this->assertSame('Invalidenstrasse 44', $normalized['street_name']);
        $this->assertSame('Berlin', $normalized['town_name']);
        $this->assertSame('DE', $normalized['country']);
        $this->assertNotEmpty($normalized['normalization_notes']);

        // SEND-STRICT: an address the adapter cannot fully structure does
        // not send — the membrane refuses the egress.
        try {
            $policy->assertSendable([
                'street_name' => 'Invalidenstrasse 44',
                'country' => 'DE',
                // no town, no post code
            ]);
            $this->fail('The membrane emitted a non-conforming egress address.');
        } catch (\DomainException $e) {
            $this->assertStringContainsString('SEND-STRICT', $e->getMessage());
        }

        // A fully structured address builds the outbound element set.
        $outbound = $policy->buildOutbound(new StructuredPostalAddress(
            streetName: 'Invalidenstrasse',
            buildingNumber: '44',
            postCode: '10115',
            townName: 'Berlin',
            country: 'DE',
        ));
        $this->assertSame('Berlin', $outbound['TwnNm']);
        $this->assertSame('DE', $outbound['Ctry']);
    }

    public function test_custodian_registration_refuses_unlicensed_or_keyless_custodians(): void
    {
        $currency = $this->reserveCurrency();

        try {
            $this->reserve->registerCustodian($currency, 'Shady Vault', 'not-a-key', 'lic:1');
            $this->fail('An invalid verification key was registered.');
        } catch (AttestationRejectedException $e) {
            $this->assertStringContainsString('not a valid Ed25519', $e->getMessage());
        }

        $pair = sodium_crypto_sign_keypair();

        try {
            $this->reserve->registerCustodian(
                $currency,
                'Unlicensed Vault',
                bin2hex(sodium_crypto_sign_publickey($pair)),
                '',
            );
            $this->fail('An unlicensed custodian was registered.');
        } catch (AttestationRejectedException $e) {
            $this->assertStringContainsString('licensed institution', $e->getMessage());
        }
    }

    // ------------------------------------------------------------------
    // Helpers.
    // ------------------------------------------------------------------

    /**
     * @return array{Currency, ReserveCustodian, string} currency, registered
     *     custodian, and the custodian's Ed25519 SECRET key.
     */
    private function custodiedCurrency(): array
    {
        $currency = $this->reserveCurrency();
        $pair = sodium_crypto_sign_keypair();

        $custodian = $this->reserve->registerCustodian(
            $currency,
            'Licensed Custody Trust',
            bin2hex(sodium_crypto_sign_publickey($pair)),
            'license:'.Str::ulid(),
        );

        return [$currency, $custodian, sodium_crypto_sign_secretkey($pair)];
    }

    private function signedIngest(
        ReserveCustodian $custodian,
        string $secret,
        int $attestedReserveMinor,
        ?string $nonce = null,
        ?\DateTimeImmutable $attestedAt = null,
    ): ReserveAttestation {
        $nonce ??= 'nonce-'.Str::ulid();
        $attestedAt ??= new \DateTimeImmutable('now');

        $signature = bin2hex(sodium_crypto_sign_detached(
            ReserveAttestationService::attestationMessage(
                $custodian->id,
                $custodian->currency_id,
                $attestedReserveMinor,
                $nonce,
                $attestedAt,
            ),
            $secret,
        ));

        return $this->reserve->ingest($custodian, $attestedReserveMinor, $nonce, $signature, $attestedAt);
    }

    private function reserveCurrency(): Currency
    {
        return $this->issuance->instantiateCurrency(new CurrencyPolicy(
            code: 'RSV'.strtoupper(Str::random(8)),
            name: 'Reserve Backed Test',
            family: CurrencyFamily::Reserve,
            decimals: 2,
            type: IssuanceType::Reserve1To1,
            baseKind: BaseKind::RealAsset,
            increaseKind: IncreaseKind::None,
            riskBearing: true,
            valueCreating: true,
            extractsFromCounterparty: false,
            declaredLossDistribution: [-0.1, 0.05],
        ));
    }
}
