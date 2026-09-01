<?php

// SPDX-License-Identifier: LicenseRef-MVL-2.0

declare(strict_types=1);

namespace Tests\Invariants;

use App\Domain\Meridian\Issuance\Exceptions\QuorumNotMetException;
use App\Domain\Meridian\Issuance\Exceptions\SensitiveDataException;
use App\Domain\Meridian\Issuance\Models\Attestation;
use App\Domain\Meridian\Issuance\Models\Verifier;
use App\Domain\Meridian\Issuance\Services\IssuanceService;
use App\Domain\Meridian\Ledger\Models\Account;
use App\Domain\Meridian\Ledger\Models\Currency;
use App\Domain\Meridian\Ledger\Models\LedgerTransaction;
use App\Domain\Meridian\Ledger\Services\LedgerService;
use Illuminate\Support\Str;
use Tests\Support\LedgerFixtures;
use Tests\TestCase;

/**
 * AttestationQuorumTest — Invariant I4 (Quorum Minting).
 *
 * DOCUMENT 4.2: sub-quorum, replayed-nonce, expired, forged-signature,
 * and same-rotation-group-collusion attestations are ALL rejected; only
 * valid independent-quorum attestations mint.
 */
final class AttestationQuorumTest extends TestCase
{
    private IssuanceService $issuance;

    private LedgerService $ledger;

    private Currency $currency;

    private Account $recipient;

    /** @var list<array{verifier: Verifier, secret: string}> */
    private array $verifiers = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->ledger = app(LedgerService::class);
        $this->issuance = app(IssuanceService::class);

        $this->currency = LedgerFixtures::currency();
        $this->recipient = LedgerFixtures::personalAccount($this->currency);

        // Register 5 active verifiers across 5 DISTINCT rotation groups,
        // plus we keep their signing secrets for the test.
        for ($i = 0; $i < 5; $i++) {
            $pair = sodium_crypto_sign_keypair();
            $verifier = new Verifier([
                'name' => "verifier-{$i}",
                'pubkey' => base64_encode(sodium_crypto_sign_publickey($pair)),
                'family_scope' => 'contribution',
                'status' => 'active',
                'rotation_group' => "group-{$i}",
                'bond' => 1_000_000,
            ]);
            $verifier->save();
            $this->verifiers[] = [
                'verifier' => $verifier,
                'secret' => sodium_crypto_sign_secretkey($pair),
            ];
        }
    }

    private function attestation(int $amountMinor = 10_000, ?string $expiresAt = null, string $subjectProof = 'zkc:commitment-abc123'): Attestation
    {
        $attestation = new Attestation([
            'currency_id' => $this->currency->id,
            'recipient_account_id' => $this->recipient->id,
            'subject_proof' => $subjectProof,
            'amount_minor' => $amountMinor,
            'nonce' => 'nonce-'.Str::ulid(),
            'expires_at' => $expiresAt ?? now()->addHour(),
            'attester_set' => [],
            'signatures' => [],
        ]);
        $attestation->save();

        return $attestation;
    }

    /** @param list<int> $signerIndexes */
    private function sign(Attestation $attestation, array $signerIndexes, bool $forge = false): Attestation
    {
        $payload = $attestation->signablePayload();
        $signatures = [];

        foreach ($signerIndexes as $i) {
            $secret = $this->verifiers[$i]['secret'];
            $message = $forge ? $payload.'|tampered' : $payload;
            $signatures[] = [
                'verifier_id' => $this->verifiers[$i]['verifier']->id,
                'signature' => base64_encode(sodium_crypto_sign_detached($message, $secret)),
            ];
        }

        $attestation->signatures = $signatures;
        $attestation->save();

        return $attestation->refresh();
    }

    public function test_a_valid_independent_quorum_attestation_mints_exactly_once(): void
    {
        $attestation = $this->sign($this->attestation(), [0, 1, 2]);

        $txn = $this->issuance->mintContribution($attestation);

        $this->assertInstanceOf(LedgerTransaction::class, $txn);
        $this->assertSame('100.00', (string) $this->ledger->balance($this->recipient->refresh())->getAmount());

        $attestation->refresh();
        $this->assertTrue($attestation->quorum_met);
        $this->assertSame($txn->id, $attestation->minted_transaction_id);
        $this->assertSame('minted', $attestation->status);
    }

    public function test_a_sub_quorum_attestation_is_rejected(): void
    {
        $attestation = $this->sign($this->attestation(), [0, 1]); // 2 < M=3

        $this->expectException(QuorumNotMetException::class);
        $this->issuance->mintContribution($attestation);
    }

    public function test_a_replayed_nonce_is_rejected_at_service_and_database(): void
    {
        $attestation = $this->sign($this->attestation(), [0, 1, 2]);
        $txn = $this->issuance->mintContribution($attestation);

        // Service layer replay.
        try {
            $this->issuance->mintContribution($attestation->refresh());
            $this->fail('I4 violated: a consumed attestation minted twice.');
        } catch (QuorumNotMetException $e) {
            $this->assertStringContainsString('replay', $e->getMessage());
        }

        // DB layer replay: try to point the attestation at another mint.
        $other = LedgerFixtures::mint($this->recipient, 1_000, $this->ledger);
        try {
            \Illuminate\Support\Facades\DB::table('attestations')
                ->where('id', $attestation->id)
                ->update(['minted_transaction_id' => $other->id]);
            $this->fail('I4 violated: the DB trigger permitted nonce re-consumption.');
        } catch (\Illuminate\Database\QueryException $e) {
            $this->assertStringContainsString('I4', $e->getMessage());
        }

        // Exactly one PoVC mint persisted.
        $this->assertSame(
            1,
            LedgerTransaction::query()->where('idempotency_key', 'povc:'.$attestation->nonce)->count()
        );
        $this->assertSame($txn->id, $attestation->refresh()->minted_transaction_id);
    }

    public function test_an_expired_attestation_is_rejected(): void
    {
        $attestation = $this->sign($this->attestation(expiresAt: now()->subMinute()->toDateTimeString()), [0, 1, 2]);

        $this->expectException(QuorumNotMetException::class);
        $this->expectExceptionMessage('expired');
        $this->issuance->mintContribution($attestation);
    }

    public function test_forged_signatures_do_not_count_toward_quorum(): void
    {
        // Three signers but every signature is over a tampered payload.
        $attestation = $this->sign($this->attestation(), [0, 1, 2], forge: true);

        $this->expectException(QuorumNotMetException::class);
        $this->issuance->mintContribution($attestation);
    }

    public function test_same_rotation_group_collusion_does_not_count_as_independence(): void
    {
        // Re-home verifiers 1 and 2 into verifier 0's rotation group:
        // three valid signatures, but only ONE independent party.
        foreach ([1, 2] as $i) {
            $v = $this->verifiers[$i]['verifier'];
            $v->rotation_group = 'group-0';
            $v->save();
        }

        $attestation = $this->sign($this->attestation(), [0, 1, 2]);

        try {
            $this->issuance->mintContribution($attestation);
            $this->fail('I4 violated: M keys from one party passed as a quorum.');
        } catch (QuorumNotMetException $e) {
            $this->assertStringContainsString('1 independent rotation group', $e->getMessage());
        }
    }

    public function test_unregistered_and_suspended_signers_do_not_count(): void
    {
        $suspended = $this->verifiers[2]['verifier'];
        $suspended->status = 'suspended';
        $suspended->save();

        // Signers: 0, 1 active + 2 suspended → only 2 count.
        $attestation = $this->sign($this->attestation(), [0, 1, 2]);

        $this->expectException(QuorumNotMetException::class);
        $this->issuance->mintContribution($attestation);
    }

    public function test_a_raw_measurement_subject_proof_is_rejected_before_any_quorum_math(): void
    {
        $attestation = $this->sign(
            $this->attestation(subjectProof: 'raw:heart_rate=72;blood_pressure=120/80'),
            [0, 1, 2],
        );

        $this->expectException(SensitiveDataException::class);
        $this->issuance->mintContribution($attestation);
    }
}
