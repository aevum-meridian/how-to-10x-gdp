<?php

/**
 * SPDX-License-Identifier: LicenseRef-MVL-2.0 AND LicenseRef-AVL-2.0
 *
 * ReplayRejectionTest — DOCUMENT 9.2 §2 (DEV-9).
 *
 * "Replayed attestations, proposals, and confirmations are no-ops."
 *
 * A replay is the cheapest attack in any distributed system: capture a
 * valid, honestly-signed message and present it again. Every mutation
 * surface in this system carries its own replay wall, and this test walks
 * ALL of them in one place: the PoVC attestation nonce, the cross-system
 * proposal, the Meridian confirmation, the reserve custodian nonce, and
 * the offline voucher's deferred-settlement nonce. In each case the
 * property is identical — the first presentation acts, every subsequent
 * presentation is a NO-OP: original result returned, nothing moved twice.
 *
 * © Maher
 */

declare(strict_types=1);

use App\Domain\Joint\EventContract\Enums\EventKind;
use App\Domain\Joint\EventContract\Enums\EventSource;
use App\Domain\Joint\EventContract\Models\EventSigner;
use App\Domain\Joint\EventContract\Services\EventChainService;
use App\Domain\Meridian\Ingress\Services\ProposalIngressService;
use App\Domain\Meridian\Issuance\Exceptions\QuorumNotMetException;
use App\Domain\Meridian\Issuance\Models\Attestation;
use App\Domain\Meridian\Issuance\Models\Verifier;
use App\Domain\Meridian\Issuance\Services\IssuanceService;
use App\Domain\Meridian\Ledger\Services\LedgerService;
use App\Domain\Meridian\Offline\Services\OfflineVoucherService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\LedgerFixtures;

describe('ReplayRejectionTest (DOCUMENT 9.2 §2)', function (): void {
    test('a replayed PoVC attestation is a no-op at the service AND unrepresentable at the DB', function (): void {
        $currency = LedgerFixtures::currency();
        $recipient = LedgerFixtures::personalAccount($currency);
        $issuance = app(IssuanceService::class);
        $ledger = new LedgerService();

        // Quorum of 3 independent verifiers signs one honest attestation.
        $attestation = new Attestation([
            'currency_id' => $currency->id,
            'recipient_account_id' => $recipient->id,
            'subject_proof' => 'zkc:commitment-'.Str::random(8),
            'amount_minor' => 5_000,
            'nonce' => 'nonce-'.Str::ulid(),
            'expires_at' => now()->addHour(),
            'attester_set' => [],
            'signatures' => [],
        ]);
        $attestation->save();

        $signatures = [];

        for ($i = 0; $i < 3; $i++) {
            $pair = sodium_crypto_sign_keypair();
            $verifier = new Verifier([
                'name' => "replay-verifier-{$i}",
                'pubkey' => base64_encode(sodium_crypto_sign_publickey($pair)),
                'family_scope' => 'contribution',
                'status' => 'active',
                'rotation_group' => "replay-group-{$i}",
                'bond' => 1_000_000,
            ]);
            $verifier->save();
            $signatures[] = [
                'verifier_id' => $verifier->id,
                'signature' => base64_encode(sodium_crypto_sign_detached(
                    $attestation->signablePayload(),
                    sodium_crypto_sign_secretkey($pair)
                )),
            ];
        }

        $attestation->signatures = $signatures;
        $attestation->save();

        // First presentation mints.
        $issuance->mintContribution($attestation->refresh());
        expect((string) $ledger->balance($recipient->refresh())->getAmount())->toBe('50.00');

        // Replay: the SAME honest attestation, presented again.
        try {
            $issuance->mintContribution($attestation->refresh());
            $this->fail('A replayed attestation minted twice.');
        } catch (QuorumNotMetException $e) {
            expect($e->getMessage())->toContain('replay rejected');
        }

        // Nothing moved on the replay.
        expect((string) $ledger->balance($recipient->refresh())->getAmount())->toBe('50.00');

        // DB layer, independently: re-pointing the consumed attestation at
        // another transaction is refused by trg_attestations_guard_mint.
        $other = LedgerFixtures::mint(LedgerFixtures::personalAccount($currency), 100);

        try {
            DB::table('attestations')->where('id', $attestation->id)->update([
                'minted_transaction_id' => $other->id,
            ]);
            $this->fail('The DB let a consumed attestation nonce be consumed again.');
        } catch (Illuminate\Database\QueryException $e) {
            expect($e->getMessage())->toContain('replay rejected');
        }

        // A second attestation row reusing the SAME nonce is unrepresentable.
        try {
            DB::table('attestations')->insert([
                'id' => (string) Str::ulid(),
                'currency_id' => $currency->id,
                'recipient_account_id' => $recipient->id,
                'subject_proof' => 'zkc:other',
                'amount_minor' => 5_000,
                'nonce' => $attestation->nonce,
                'expires_at' => now()->addHour(),
                'attester_set' => '[]',
                'signatures' => '[]',
                'created_at' => now(),
            ]);
            $this->fail('The DB accepted a second attestation under a used nonce.');
        } catch (Illuminate\Database\QueryException $e) {
            expect($e->getMessage())->toContain('attestations_nonce_unique');
        }
    });

    test('a replayed cross-system proposal is a no-op: the terminal outcome is returned, not re-executed', function (): void {
        [$chain, $aevumSecret, $meridianSecret] = registerBothLegSigners();

        $currency = LedgerFixtures::currency();
        $alice = LedgerFixtures::personalAccount($currency);
        $bob = LedgerFixtures::personalAccount($currency);
        LedgerFixtures::mint($alice, 20_000);

        $proposal = $chain->append(
            EventSource::Aevum,
            EventKind::ProposalTransfer,
            [
                'from_account_id' => $alice->id,
                'to_account_id' => $bob->id,
                'currency_id' => $currency->id,
                'amount_minor' => 3_000,
                'holder_authorization_ref' => 'auth:replay-prop',
            ],
            'prop-replay-'.Str::random(10),
            $aevumSecret,
        )->event;

        $ingress = app(ProposalIngressService::class);
        $first = $ingress->receiveProposal($proposal, $meridianSecret);
        expect($first->committed)->toBeTrue();

        $ledger = new LedgerService();
        expect((string) $ledger->balance($bob->refresh())->getAmount())->toBe('30.00');

        // Replay the SAME committed proposal. The outcome is handed back;
        // the transfer does not run again.
        $replay = $ingress->receiveProposal($proposal->refresh(), $meridianSecret);

        expect($replay->committed)->toBeTrue()
            ->and($replay->transactionId)->toBe($first->transactionId)
            ->and((string) $ledger->balance($bob->refresh())->getAmount())->toBe('30.00');

        // Exactly one confirmation exists for the proposal — the replay
        // did not speak a second time.
        expect(DB::table('cross_system_events')->where('idempotency_key', 'confirm:'.$proposal->id)->count())->toBe(1);
    });

    test('a replayed REJECTED proposal stays rejected: a retry cannot convert a refusal into a commit', function (): void {
        [$chain, $aevumSecret, $meridianSecret] = registerBothLegSigners();

        $currency = LedgerFixtures::currency();
        $alice = LedgerFixtures::personalAccount($currency);
        $bob = LedgerFixtures::personalAccount($currency);
        LedgerFixtures::mint($alice, 1_000);

        // Overdraft proposal: rejected on first presentation.
        $proposal = $chain->append(
            EventSource::Aevum,
            EventKind::ProposalTransfer,
            [
                'from_account_id' => $alice->id,
                'to_account_id' => $bob->id,
                'currency_id' => $currency->id,
                'amount_minor' => 999_999,
                'holder_authorization_ref' => 'auth:replay-overdraft',
            ],
            'prop-overdraft-'.Str::random(10),
            $aevumSecret,
        )->event;

        $ingress = app(ProposalIngressService::class);
        $first = $ingress->receiveProposal($proposal, $meridianSecret);
        expect($first->committed)->toBeFalse();

        // Now fund the account. The REPLAY must still return the original
        // rejection — a terminal outcome is a historical fact, not a retry.
        LedgerFixtures::mint($alice, 2_000_000);

        $replay = $ingress->receiveProposal($proposal->refresh(), $meridianSecret);

        expect($replay->committed)->toBeFalse()
            ->and($replay->rejectionReason)->toBe($first->rejectionReason);

        $ledger = new LedgerService();
        expect((string) $ledger->balance($bob->refresh())->getAmount())->toBe('0.00');
    });

    test('a replayed confirmation is a no-op on the event chain: one voice, spoken once', function (): void {
        [$chain, , $meridianSecret] = registerBothLegSigners();

        $key = 'confirm:'.Str::ulid();
        $payload = ['proposal_event_id' => (string) Str::ulid(), 'transaction_id' => (string) Str::ulid()];

        $first = $chain->append(EventSource::Meridian, EventKind::ConfirmationCommitted, $payload, $key, $meridianSecret);
        $replay = $chain->append(EventSource::Meridian, EventKind::ConfirmationCommitted, $payload, $key, $meridianSecret);

        expect($first->replayed)->toBeFalse()
            ->and($replay->replayed)->toBeTrue()
            ->and($replay->event->id)->toBe($first->event->id)
            ->and(DB::table('cross_system_events')->where('idempotency_key', $key)->count())->toBe(1);

        // And the chain remains verifiable end to end — the replay left
        // no seam in the hash chain.
        expect($chain->verifyChain()->intact)->toBeTrue();
    });

    test('a replayed offline deferred settlement is refused: the same signed IOU cannot settle twice', function (): void {
        $currency = LedgerFixtures::currency();
        $holder = LedgerFixtures::personalAccount($currency);
        $payee = LedgerFixtures::personalAccount($currency);
        LedgerFixtures::mint($holder, 50_000);

        $pair = sodium_crypto_sign_keypair();
        $vouchers = app(OfflineVoucherService::class);

        $voucher = $vouchers->reserve(
            $holder->refresh(),
            10_000,
            base64_encode(sodium_crypto_sign_publickey($pair)),
            'auth:offline-reserve',
        );

        $nonce = 'deferred-'.Str::ulid();
        $signature = base64_encode(sodium_crypto_sign_detached(
            OfflineVoucherService::deferredMessage($voucher->id, $payee->id, 2_500, $nonce),
            sodium_crypto_sign_secretkey($pair),
        ));

        // First presentation settles.
        $vouchers->settleDeferred($voucher, $payee, 2_500, $nonce, $signature);

        $ledger = new LedgerService();
        expect((string) $ledger->balance($payee->refresh())->getAmount())->toBe('25.00');

        // The identical signed record, presented again.
        try {
            $vouchers->settleDeferred($voucher->refresh(), $payee, 2_500, $nonce, $signature);
            $this->fail('The same signed offline record settled twice.');
        } catch (DomainException $e) {
            expect($e->getMessage())->toContain('replay is refused');
        }

        expect((string) $ledger->balance($payee->refresh())->getAmount())->toBe('25.00');
    });

    test('a replayed reserve attestation nonce is refused even with a fresh, honest signature', function (): void {
        $issuance = app(IssuanceService::class);
        $reserve = app(App\Domain\Meridian\Reserve\Services\ReserveAttestationService::class);

        $currency = $issuance->instantiateCurrency(new App\Domain\Meridian\Issuance\Data\CurrencyPolicy(
            code: 'RSV'.strtoupper(Str::random(8)),
            name: 'Replay Reserve Test',
            family: App\Domain\Meridian\Ledger\Enums\CurrencyFamily::Reserve,
            decimals: 2,
            type: App\Domain\Meridian\Issuance\Enums\IssuanceType::Reserve1To1,
            baseKind: App\Domain\Meridian\Issuance\Enums\BaseKind::RealAsset,
            increaseKind: App\Domain\Meridian\Issuance\Enums\IncreaseKind::None,
            riskBearing: true,
            valueCreating: true,
            extractsFromCounterparty: false,
            declaredLossDistribution: [-0.1, 0.05],
        ));

        $pair = sodium_crypto_sign_keypair();
        $custodian = $reserve->registerCustodian(
            $currency,
            'Replay Custody Trust',
            bin2hex(sodium_crypto_sign_publickey($pair)),
            'license:'.Str::ulid(),
        );
        $secret = sodium_crypto_sign_secretkey($pair);

        $nonce = 'nonce-'.Str::ulid();
        $attestedAt = new DateTimeImmutable('now');
        $sign = fn (int $amount, DateTimeImmutable $at): string => bin2hex(sodium_crypto_sign_detached(
            App\Domain\Meridian\Reserve\Services\ReserveAttestationService::attestationMessage(
                $custodian->id,
                $custodian->currency_id,
                $amount,
                $nonce,
                $at
            ),
            $secret,
        ));

        $reserve->ingest($custodian, 800_000, $nonce, $sign(800_000, $attestedAt), $attestedAt);

        // Same nonce, honestly re-signed a minute later — still refused.
        $later = $attestedAt->modify('+1 minute');

        try {
            $reserve->ingest($custodian, 800_000, $nonce, $sign(800_000, $later), $later);
            $this->fail('A replayed reserve attestation nonce was accepted.');
        } catch (App\Domain\Meridian\Reserve\Exceptions\AttestationRejectedException $e) {
            expect($e->getMessage())->toContain('replayed');
        }

        expect(DB::table('reserve_attestations')->where('nonce', $nonce)->count())->toBe(1);
    });
});

/**
 * Register active Ed25519 signers for both legs and return
 * [chain, aevumSecret, meridianSecret].
 *
 * @return array{EventChainService, string, string}
 */
function registerBothLegSigners(): array
{
    $aevumPair = sodium_crypto_sign_keypair();
    $meridianPair = sodium_crypto_sign_keypair();

    foreach ([['aevum', $aevumPair], ['meridian', $meridianPair]] as [$source, $pair]) {
        EventSigner::query()->create([
            'source' => $source,
            'public_key' => base64_encode(sodium_crypto_sign_publickey($pair)),
            'status' => 'active',
            'registered_at' => now(),
        ]);
    }

    return [
        app(EventChainService::class),
        sodium_crypto_sign_secretkey($aevumPair),
        sodium_crypto_sign_secretkey($meridianPair),
    ];
}
