<?php

// SPDX-License-Identifier: LicenseRef-MVL-2.0

declare(strict_types=1);

namespace Tests\Invariants;

use App\Domain\Meridian\Dispute\Data\ArbitrationRuling;
use App\Domain\Meridian\Dispute\Data\Resolution;
use App\Domain\Meridian\Dispute\Enums\ClawbackTarget;
use App\Domain\Meridian\Dispute\Enums\DisputeStatus;
use App\Domain\Meridian\Dispute\Exceptions\InvalidReversalException;
use App\Domain\Meridian\Dispute\Models\AttestationDispute;
use App\Domain\Meridian\Dispute\Models\Clawback;
use App\Domain\Meridian\Dispute\Services\DisputeService;
use App\Domain\Meridian\Issuance\Models\Attestation;
use App\Domain\Meridian\Ledger\Data\EntryDraft;
use App\Domain\Meridian\Ledger\Data\TransactionDraft;
use App\Domain\Meridian\Ledger\Enums\ReversalReason;
use App\Domain\Meridian\Ledger\Enums\TransactionKind;
use App\Domain\Meridian\Ledger\Exceptions\PunitiveDebitException;
use App\Domain\Meridian\Ledger\Models\Account;
use App\Domain\Meridian\Ledger\Models\Currency;
use App\Domain\Meridian\Ledger\Models\Entry;
use App\Domain\Meridian\Ledger\Models\LedgerTransaction;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\LedgerFixtures;
use Tests\TestCase;

/**
 * NonPunishmentTest — Invariant I6 (No Punitive Debit — The Spine).
 *
 * DOCUMENT 0.1 I6 / DOCUMENT 4.3, the four mandated properties:
 *  (a) a debit referencing no specific mint is ALWAYS rejected — there
 *      is no path;
 *  (b) a reversal never reduces a balance below the holder's undisputed
 *      credits;
 *  (c) an innocent holder never has any balance reduced by any dispute
 *      they were not the fraudulent party to;
 *  (d) the ONLY successful personal-contribution debit in the entire
 *      test surface is one with both a valid target-mint and a closed
 *      fraud case.
 */
final class NonPunishmentTest extends TestCase
{
    private DisputeService $disputes;

    private Currency $currency;

    protected function setUp(): void
    {
        parent::setUp();
        $this->disputes = app(DisputeService::class);
        $this->currency = LedgerFixtures::currency();
    }

    /**
     * A quorum-met attestation whose mint has posted — the provisional
     * mint a dispute can target.
     *
     * @return array{Attestation, LedgerTransaction}
     */
    private function mintWithAttestation(Account $to, int $amountMinor): array
    {
        $attestation = new Attestation([
            'currency_id' => $this->currency->id,
            'recipient_account_id' => $to->id,
            'subject_proof' => 'zkc:'.Str::random(16),
            'amount_minor' => $amountMinor,
            'nonce' => 'nonce-'.Str::ulid(),
            'expires_at' => now()->addHour(),
            'quorum_met' => true,
        ]);
        $attestation->save();

        $mint = LedgerFixtures::mint($to, $amountMinor, $this->disputes);

        $attestation->minted_transaction_id = $mint->id;
        $attestation->status = 'minted';
        $attestation->save();

        return [$attestation, $mint];
    }

    private function closeAsFraud(Attestation $attestation, Account $fraudulentParty): Resolution
    {
        $dispute = $this->disputes->openDispute($attestation, 'challenger-1', 1_000);
        $dispute = $this->disputes->escalate($dispute, 2_000);
        $dispute = $this->disputes->escalate($dispute, 4_000);
        $this->assertSame(DisputeStatus::Arbitrating, $dispute->status);

        return $this->disputes->arbitrate($dispute, new ArbitrationRuling(
            fraudProven: true,
            fraudulentPartyAccountId: $fraudulentParty->id,
            decisionReceipt: 'Public ruling: the recipient fabricated the contribution evidence.',
            arbitratorSignature: 'sig:'.Str::random(32),
        ));
    }

    // ------------------------------------------------------------------
    // (a) A debit referencing no specific mint is ALWAYS rejected.
    // ------------------------------------------------------------------

    public function test_property_a_no_path_exists_for_a_debit_referencing_no_specific_mint(): void
    {
        $amina = LedgerFixtures::personalAccount($this->currency);
        LedgerFixtures::mint($amina, 100_000, $this->disputes);
        $issuance = LedgerFixtures::systemAccount($this->currency, \App\Domain\Meridian\Ledger\Enums\SystemAccountRole::Issuance);

        // Path 1: the public post() surface refuses the kind outright.
        try {
            $this->disputes->post(new TransactionDraft(
                kind: TransactionKind::ArbitrationReversal,
                entries: [
                    new EntryDraft($amina->id, $this->currency->id, -5_000),
                    new EntryDraft($issuance->id, $this->currency->id, 5_000),
                ],
                idempotencyKey: 'punitive-attempt-1',
            ));
            $this->fail('I6 violated: post() authored an arbitration reversal.');
        } catch (PunitiveDebitException $e) {
            $this->assertStringContainsString('I6', $e->getMessage());
        }

        // Path 2: reverse() refuses the arbitration reason.
        $someTxn = LedgerFixtures::mint($amina, 1_000, $this->disputes);
        try {
            $this->disputes->reverse($someTxn, ReversalReason::ArbitrationReversal);
            $this->fail('I6 violated: reverse() authored an arbitration reversal.');
        } catch (PunitiveDebitException $e) {
            $this->assertStringContainsString('I6', $e->getMessage());
        }

        // Path 3: a raw unauthorized personal debit (no refs at all).
        try {
            DB::transaction(function () use ($amina, $issuance): void {
                $id = (string) Str::ulid();
                DB::statement(
                    "INSERT INTO transactions (id, kind, status, idempotency_key) VALUES (?, 'transfer', 'posted', ?)",
                    [$id, 'punitive-attempt-3']
                );
                DB::statement(
                    'INSERT INTO entries (transaction_id, account_id, currency_id, amount, balance_after) VALUES (?, ?, ?, -5000, 0)',
                    [$id, $amina->id, $this->currency->id]
                );
                DB::statement(
                    'INSERT INTO entries (transaction_id, account_id, currency_id, amount, balance_after) VALUES (?, ?, ?, 5000, 0)',
                    [$id, $issuance->id, $this->currency->id]
                );
            });
            $this->fail('I6 violated: an unauthorized personal debit persisted.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('punitive debit', $e->getMessage());
        }

        // Path 4: an arbitration_reversal row lacking the mint reference
        // cannot even take shape (table CHECK).
        try {
            DB::statement(
                "INSERT INTO transactions (id, kind, status, idempotency_key, arbitration_case_id) VALUES (?, 'arbitration_reversal', 'posted', ?, ?)",
                [(string) Str::ulid(), 'punitive-attempt-4', (string) Str::ulid()]
            );
            $this->fail('I6 violated: an arbitration reversal without a target mint took shape.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('transactions_arbitration_shape_check', $e->getMessage());
        }

        // Amina's balance is untouched by every attempt.
        $this->assertSame(101_000, $amina->refresh()->balance_minor);
    }

    public function test_property_a_db_layer_rejects_a_reversal_whose_case_is_not_closed_or_mismatched(): void
    {
        $fraudster = LedgerFixtures::personalAccount($this->currency);
        [$attestation, $mint] = $this->mintWithAttestation($fraudster, 50_000);

        // An OPEN (not closed) case.
        $dispute = $this->disputes->openDispute($attestation, 'challenger-1', 1_000);

        try {
            DB::transaction(function () use ($fraudster, $mint, $dispute): void {
                $issuance = LedgerFixtures::systemAccount($this->currency, \App\Domain\Meridian\Ledger\Enums\SystemAccountRole::Issuance);
                $id = (string) Str::ulid();
                DB::statement(
                    "INSERT INTO transactions (id, kind, status, idempotency_key, reverses_mint_transaction_id, arbitration_case_id) VALUES (?, 'arbitration_reversal', 'posted', ?, ?, ?)",
                    [$id, 'db-bypass-open-case', $mint->id, $dispute->id]
                );
                DB::statement(
                    'INSERT INTO entries (transaction_id, account_id, currency_id, amount, balance_after) VALUES (?, ?, ?, -50000, 0)',
                    [$id, $fraudster->id, $this->currency->id]
                );
                DB::statement(
                    'INSERT INTO entries (transaction_id, account_id, currency_id, amount, balance_after) VALUES (?, ?, ?, 50000, 0)',
                    [$id, $issuance->id, $this->currency->id]
                );
            });
            $this->fail('I6 violated: a reversal against an open case persisted at the DB layer.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('not a closed fraud ruling', $e->getMessage());
        }

        $this->assertSame(50_000, $fraudster->refresh()->balance_minor);
    }

    // ------------------------------------------------------------------
    // (b) The undisputed-credits floor is absolute.
    // ------------------------------------------------------------------

    public function test_property_b_a_reversal_never_reduces_a_balance_below_undisputed_credits(): void
    {
        $fraudster = LedgerFixtures::personalAccount($this->currency);
        $peer = LedgerFixtures::personalAccount($this->currency);

        // 100_000 undisputed + 50_000 fraudulent, then the fraudster
        // spends 20_000: balance 130_000, undisputed floor 100_000.
        LedgerFixtures::mint($fraudster, 100_000, $this->disputes);
        [$attestation, $mint] = $this->mintWithAttestation($fraudster, 50_000);
        LedgerFixtures::transfer($fraudster, $peer, 20_000, $this->disputes);

        $resolution = $this->closeAsFraud($attestation, $fraudster);
        $txn = $this->disputes->applyArbitrationReversal($resolution);

        // Recoverable = min(50_000, 130_000 - 100_000) = 30_000 — bounded
        // by the floor, not the full mint.
        $this->assertSame(100_000, $fraudster->refresh()->balance_minor);
        $this->assertTrue($this->disputes->reconcile($fraudster)->consistent);

        /** @var Entry $debit */
        $debit = Entry::query()->where('transaction_id', $txn->id)->where('amount', '<', 0)->firstOrFail();
        $this->assertSame(-30_000, (int) $debit->amount);

        // A second application recovers nothing further — the floor holds.
        try {
            $this->disputes->applyArbitrationReversal($resolution);
            $this->fail('I6 violated: a second reversal breached the undisputed-credits floor.');
        } catch (InvalidReversalException $e) {
            $this->assertStringContainsString('undisputed-credits floor', $e->getMessage());
        }
        $this->assertSame(100_000, $fraudster->refresh()->balance_minor);
    }

    public function test_property_b_db_layer_independently_enforces_the_floor_and_the_amount_bound(): void
    {
        $fraudster = LedgerFixtures::personalAccount($this->currency);
        LedgerFixtures::mint($fraudster, 100_000, $this->disputes);
        [$attestation, $mint] = $this->mintWithAttestation($fraudster, 50_000);
        $resolution = $this->closeAsFraud($attestation, $fraudster);
        $dispute = AttestationDispute::query()->findOrFail($resolution->disputeId);
        $issuance = LedgerFixtures::systemAccount($this->currency, \App\Domain\Meridian\Ledger\Enums\SystemAccountRole::Issuance);

        // Over-bound: |amount| > mint credit — even with valid references.
        try {
            DB::transaction(function () use ($fraudster, $mint, $dispute, $issuance): void {
                $id = (string) Str::ulid();
                DB::statement(
                    "INSERT INTO transactions (id, kind, status, idempotency_key, reverses_mint_transaction_id, arbitration_case_id) VALUES (?, 'arbitration_reversal', 'posted', ?, ?, ?)",
                    [$id, 'db-bypass-over-bound', $mint->id, $dispute->id]
                );
                DB::statement(
                    'INSERT INTO entries (transaction_id, account_id, currency_id, amount, balance_after) VALUES (?, ?, ?, -60000, 0)',
                    [$id, $fraudster->id, $this->currency->id]
                );
                DB::statement(
                    'INSERT INTO entries (transaction_id, account_id, currency_id, amount, balance_after) VALUES (?, ?, ?, 60000, 0)',
                    [$id, $issuance->id, $this->currency->id]
                );
            });
            $this->fail('I6 violated: an over-bound reversal persisted at the DB layer.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('exceeds the target mint', $e->getMessage());
        }

        $this->assertSame(150_000, $fraudster->refresh()->balance_minor);
    }

    // ------------------------------------------------------------------
    // (c) An innocent holder's balance is never reduced.
    // ------------------------------------------------------------------

    public function test_property_c_an_innocent_holder_is_never_debited_by_a_dispute(): void
    {
        $innocent = LedgerFixtures::personalAccount($this->currency);
        [$attestation, $mint] = $this->mintWithAttestation($innocent, 50_000);

        // The attester committed the fraud; the recipient is innocent.
        $dispute = $this->disputes->openDispute($attestation, 'challenger-1', 1_000);
        $dispute = $this->disputes->escalate($dispute, 2_000);
        $dispute = $this->disputes->escalate($dispute, 4_000);
        $resolution = $this->disputes->arbitrate($dispute, new ArbitrationRuling(
            fraudProven: true,
            fraudulentPartyAccountId: null, // recipient NOT the fraudster
            decisionReceipt: 'Public ruling: the attesters colluded; the recipient acted in good faith.',
            arbitratorSignature: 'sig:'.Str::random(32),
        ));

        // The reversal path refuses to touch the innocent holder.
        try {
            $this->disputes->applyArbitrationReversal($resolution);
            $this->fail('I6 violated: an innocent holder was debited.');
        } catch (InvalidReversalException $e) {
            $this->assertStringContainsString('innocent', $e->getMessage());
        }

        $this->assertSame(50_000, $innocent->refresh()->balance_minor);

        // The loss falls on the bonds: both bond clawbacks recorded, and
        // no clawback row can even target a generic personal account.
        $targets = Clawback::query()->where('dispute_id', $dispute->id)->pluck('target');
        $this->assertCount(2, $targets);
        $this->assertTrue($targets->contains(ClawbackTarget::AttesterBond));
        $this->assertTrue($targets->contains(ClawbackTarget::IssuerBond));

        try {
            DB::table('clawbacks')->insert([
                'id' => (string) Str::ulid(),
                'dispute_id' => $dispute->id,
                'target' => 'personal_account',
                'amount' => 50_000,
            ]);
            $this->fail('I6 violated: a generic personal-account clawback target took shape.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('clawbacks_target_check', $e->getMessage());
        }
    }

    public function test_property_c_a_holder_who_never_received_the_mint_cannot_be_its_clawback_source(): void
    {
        $fraudster = LedgerFixtures::personalAccount($this->currency);
        $bystander = LedgerFixtures::personalAccount($this->currency);
        LedgerFixtures::mint($bystander, 80_000, $this->disputes);
        [$attestation, $mint] = $this->mintWithAttestation($fraudster, 50_000);
        $resolution = $this->closeAsFraud($attestation, $fraudster);
        $dispute = AttestationDispute::query()->findOrFail($resolution->disputeId);
        $issuance = LedgerFixtures::systemAccount($this->currency, \App\Domain\Meridian\Ledger\Enums\SystemAccountRole::Issuance);

        // DB-layer attempt to source the clawback from the bystander.
        try {
            DB::transaction(function () use ($bystander, $mint, $dispute, $issuance): void {
                $id = (string) Str::ulid();
                DB::statement(
                    "INSERT INTO transactions (id, kind, status, idempotency_key, reverses_mint_transaction_id, arbitration_case_id) VALUES (?, 'arbitration_reversal', 'posted', ?, ?, ?)",
                    [$id, 'db-bypass-bystander', $mint->id, $dispute->id]
                );
                DB::statement(
                    'INSERT INTO entries (transaction_id, account_id, currency_id, amount, balance_after) VALUES (?, ?, ?, -50000, 0)',
                    [$id, $bystander->id, $this->currency->id]
                );
                DB::statement(
                    'INSERT INTO entries (transaction_id, account_id, currency_id, amount, balance_after) VALUES (?, ?, ?, 50000, 0)',
                    [$id, $issuance->id, $this->currency->id]
                );
            });
            $this->fail('I6 violated: a bystander was made the source of a clawback.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('never received a credit from mint', $e->getMessage());
        }

        $this->assertSame(80_000, $bystander->refresh()->balance_minor);
    }

    // ------------------------------------------------------------------
    // (d) The only successful personal debit carries both references.
    // ------------------------------------------------------------------

    public function test_property_d_the_only_successful_non_holder_personal_debit_carries_both_references(): void
    {
        $fraudster = LedgerFixtures::personalAccount($this->currency);
        $honest = LedgerFixtures::personalAccount($this->currency);

        LedgerFixtures::mint($honest, 30_000, $this->disputes);
        LedgerFixtures::transfer($honest, $fraudster, 5_000, $this->disputes); // holder-authorized debit
        [$attestation, $mint] = $this->mintWithAttestation($fraudster, 50_000);

        $resolution = $this->closeAsFraud($attestation, $fraudster);
        $reversal = $this->disputes->applyArbitrationReversal($resolution);

        // The full fraudulent mint was recovered (nothing was spent).
        $this->assertSame(5_000, $fraudster->refresh()->balance_minor);
        $this->assertSame(25_000, $honest->refresh()->balance_minor);
        $this->assertTrue($this->disputes->reconcile($fraudster)->consistent);
        $this->assertSame([], $this->disputes->proveSupplyIntegrity());

        // Sweep the ENTIRE test surface: every negative entry against a
        // personal account either carries a holder authorization or
        // belongs to a transaction with BOTH I6 references — and the only
        // such transaction is the one reversal above.
        $personalDebits = Entry::query()
            ->where('amount', '<', 0)
            ->whereIn('account_id', Account::query()->where('owner_type', 'person')->pluck('id'))
            ->get();

        $arbitrationTxnIds = [];

        foreach ($personalDebits as $debit) {
            if ($debit->holder_authorization_ref !== null) {
                continue; // The consensual path (I10).
            }

            $txn = LedgerTransaction::query()->findOrFail($debit->transaction_id);
            $this->assertSame(TransactionKind::ArbitrationReversal, $txn->kind);
            $this->assertNotNull($txn->reverses_mint_transaction_id, 'I6: reversal must reference a specific mint.');
            $this->assertNotNull($txn->arbitration_case_id, 'I6: reversal must reference a closed case.');
            $arbitrationTxnIds[] = $txn->id;
        }

        $this->assertSame([$reversal->id], array_values(array_unique($arbitrationTxnIds)));

        // And the closed case really is closed fraud.
        $case = AttestationDispute::query()->findOrFail($resolution->disputeId);
        $this->assertSame(DisputeStatus::ResolvedFraud, $case->status);
        $this->assertNotNull($case->case_closed_at);
    }
}
