<?php

/**
 * SPDX-License-Identifier: LicenseRef-MVL-2.0
 *
 * DEV-4.3 — DISPUTE / CLAWBACK / ARBITRATION ENGINE service.
 *
 * THE ONE RULE ABOVE ALL RULES: applyArbitrationReversal() is the SINGLE
 * method in the entire system that can debit a personal contribution
 * balance, and only under the I6-revised predicate:
 *
 *   - it references a specific fraudulent mint transaction_id,
 *   - it references a CLOSED arbitration case (resolved_fraud),
 *   - |amount| ≤ the target mint's credit,
 *   - the resulting balance never drops below the holder's undisputed
 *     credits,
 *   - and it only ever touches the account of the PROVEN fraudulent
 *     party — an innocent holder's balance is never the source of
 *     clawback (bonds are).
 *
 * This class extends LedgerService solely to reach the protected
 * persist() posting path: the public post() rejects the
 * arbitration_reversal kind outright, so no other component — including
 * anything else holding a LedgerService — can author this transaction.
 * The database trigger (ledger_guard_personal_debit, full I6-revised
 * form) re-verifies every conjunct independently.
 *
 * © Maher
 */

declare(strict_types=1);

namespace App\Domain\Meridian\Dispute\Services;

use App\Domain\Meridian\Dispute\Data\ArbitrationRuling;
use App\Domain\Meridian\Dispute\Data\Resolution;
use App\Domain\Meridian\Dispute\Enums\ClawbackTarget;
use App\Domain\Meridian\Dispute\Enums\DisputeStatus;
use App\Domain\Meridian\Dispute\Exceptions\InvalidReversalException;
use App\Domain\Meridian\Dispute\Models\AttestationDispute;
use App\Domain\Meridian\Dispute\Models\Clawback;
use App\Domain\Meridian\Issuance\Models\Attestation;
use App\Domain\Meridian\Ledger\Data\EntryDraft;
use App\Domain\Meridian\Ledger\Data\TransactionDraft;
use App\Domain\Meridian\Ledger\Enums\SystemAccountRole;
use App\Domain\Meridian\Ledger\Enums\TransactionKind;
use App\Domain\Meridian\Ledger\Models\Account;
use App\Domain\Meridian\Ledger\Models\Entry;
use App\Domain\Meridian\Ledger\Models\LedgerTransaction;
use App\Domain\Meridian\Ledger\Services\LedgerService;
use Illuminate\Support\Facades\DB;

class DisputeService extends LedgerService
{
    /**
     * Open a dispute against a SPECIFIC provisional mint (never against
     * a person), posting a challenger bond.
     */
    public function openDispute(Attestation $attestation, string $challengerId, int $bond): AttestationDispute
    {
        if ($attestation->minted_transaction_id === null) {
            throw new InvalidReversalException(
                'A dispute must target a specific minted transaction; this attestation has not minted.'
            );
        }

        if ($bond <= 0) {
            throw new InvalidReversalException('A dispute requires a positive challenger bond (Adl — proportionality).');
        }

        return DB::transaction(function () use ($attestation, $challengerId, $bond): AttestationDispute {
            $dispute = new AttestationDispute([
                'attestation_id' => $attestation->id,
                'mint_transaction_id' => $attestation->minted_transaction_id,
                'round' => 1,
                'bond' => $bond,
                'challenger_id' => $challengerId,
                'status' => DisputeStatus::Open,
            ]);
            $dispute->save();

            $attestation->status = 'disputed';
            $attestation->save();

            return $dispute;
        });
    }

    /**
     * Advance to the next rising-bond round. The rising bond filters
     * frivolous challenges; a bond that does not rise is rejected.
     */
    public function escalate(AttestationDispute $dispute, int $newBond): AttestationDispute
    {
        if ($dispute->status->isClosed()) {
            throw new InvalidReversalException("Dispute {$dispute->id} is closed and cannot escalate.");
        }

        if ($newBond <= $dispute->bond) {
            throw new InvalidReversalException(
                "Escalation requires a rising bond: new bond {$newBond} must exceed current {$dispute->bond}."
            );
        }

        $dispute->round += 1;
        $dispute->bond = $newBond;
        $dispute->status = $dispute->round >= 3 ? DisputeStatus::Arbitrating : DisputeStatus::Escalated;
        $dispute->save();

        return $dispute;
    }

    /**
     * The public human arbitration tier rules, in the open, with a signed
     * decision receipt. Closes the case; on a fraud ruling, records the
     * bond-target clawbacks (attester first, issuer second). The specific
     * mint is touched only by applyArbitrationReversal(), and only where
     * the recipient is the proven fraudulent party.
     */
    public function arbitrate(AttestationDispute $dispute, ArbitrationRuling $ruling): Resolution
    {
        if ($dispute->status->isClosed()) {
            throw new InvalidReversalException("Dispute {$dispute->id} is already closed; the public ruling is final.");
        }

        return DB::transaction(function () use ($dispute, $ruling): Resolution {
            $dispute->status = $ruling->fraudProven ? DisputeStatus::ResolvedFraud : DisputeStatus::ResolvedValid;
            $dispute->case_closed_at = now();
            $dispute->resolution = [
                'fraud_proven' => $ruling->fraudProven,
                'fraudulent_party_account_id' => $ruling->fraudulentPartyAccountId,
                'decision_receipt' => $ruling->decisionReceipt,
                'arbitrator_signature' => $ruling->arbitratorSignature,
            ];
            $dispute->save();

            if ($ruling->fraudProven) {
                // Clawback fires FIRST against the attester and issuer
                // bonds — never a generic personal account (DB CHECK).
                foreach ([ClawbackTarget::AttesterBond, ClawbackTarget::IssuerBond] as $target) {
                    $clawback = new Clawback([
                        'dispute_id' => $dispute->id,
                        'target' => $target,
                        'amount' => $dispute->bond,
                    ]);
                    $clawback->save();
                }
            }

            return new Resolution(
                disputeId: $dispute->id,
                mintTransactionId: $dispute->mint_transaction_id,
                fraudProven: $ruling->fraudProven,
                fraudulentPartyAccountId: $ruling->fraudulentPartyAccountId,
            );
        });
    }

    /**
     * THE ONLY METHOD IN THE ENTIRE SYSTEM that can debit a personal
     * contribution balance. Every conjunct of the I6-revised predicate is
     * verified here, and again — independently — by the database trigger.
     * A Resolution is a claim, not an authorization: this method trusts
     * nothing it did not re-derive from the database.
     */
    public function applyArbitrationReversal(Resolution $resolution): LedgerTransaction
    {
        $dispute = AttestationDispute::query()->findOrFail($resolution->disputeId);

        // (2) A CLOSED arbitration case that ruled fraud.
        if ($dispute->status !== DisputeStatus::ResolvedFraud || $dispute->case_closed_at === null) {
            throw new InvalidReversalException(
                "I6: case {$dispute->id} is not a closed fraud ruling (status {$dispute->status->value}); the reversal path requires a closed case."
            );
        }

        // (1) A specific fraudulent mint, bound to that same case.
        if ($dispute->mint_transaction_id !== $resolution->mintTransactionId) {
            throw new InvalidReversalException(
                "I6: case {$dispute->id} rules on mint {$dispute->mint_transaction_id}, not {$resolution->mintTransactionId}; a case may only unwind its own mint."
            );
        }

        $mint = LedgerTransaction::query()->findOrFail($dispute->mint_transaction_id);

        if ($mint->kind !== TransactionKind::Mint) {
            throw new InvalidReversalException(
                "I6: transaction {$mint->id} is not a mint; only a specific fraudulent mint may be reversed."
            );
        }

        // The credited recipient of the mint.
        /** @var Entry $creditEntry */
        $creditEntry = Entry::query()
            ->where('transaction_id', $mint->id)
            ->where('amount', '>', 0)
            ->firstOrFail();

        $recipient = Account::query()->findOrFail($creditEntry->account_id);

        // Innocent-holder protection: the reversal touches the mint ONLY
        // where the recipient is the PROVEN fraudulent party.
        if ($resolution->fraudulentPartyAccountId === null
            || $resolution->fraudulentPartyAccountId !== $recipient->id
            || ($dispute->resolution['fraudulent_party_account_id'] ?? null) !== $recipient->id) {
            throw new InvalidReversalException(
                'I6: the mint recipient is not the proven fraudulent party; an innocent '
                .'holder\'s balance is never the source of clawback — the attester and '
                .'issuer bonds are.'
            );
        }

        // (3) Bound: |amount| ≤ the target mint's credit. (4) Floor: the
        // resulting balance never drops below undisputed credits. The
        // recoverable amount honors both simultaneously.
        $mintCredit = (int) $creditEntry->amount;
        $balance = $recipient->refresh()->balance_minor;
        $undisputed = $this->undisputedCredits($recipient);
        $recoverable = min($mintCredit, $balance - $undisputed);

        if ($recoverable <= 0) {
            throw new InvalidReversalException(
                "I6: nothing is recoverable from account {$recipient->id} without breaching "
                ."the undisputed-credits floor (balance {$balance}, undisputed {$undisputed}); "
                .'the remaining loss falls on the attester and issuer bonds.'
            );
        }

        $issuance = Account::query()
            ->where('currency_id', $recipient->currency_id)
            ->where('system_role', SystemAccountRole::Issuance->value)
            ->firstOrFail();

        return DB::transaction(function () use ($dispute, $mint, $recipient, $issuance, $recoverable): LedgerTransaction {
            // The single permitted posting path: protected persist(),
            // carrying BOTH references the predicate demands.
            $transaction = $this->persist(new TransactionDraft(
                kind: TransactionKind::ArbitrationReversal,
                entries: [
                    new EntryDraft($recipient->id, $recipient->currency_id, -$recoverable),
                    new EntryDraft($issuance->id, $recipient->currency_id, $recoverable),
                ],
                idempotencyKey: 'arbitration-reversal:'.$dispute->id,
                metadata: [
                    'dispute_id' => $dispute->id,
                    'decision_receipt' => is_string($dispute->resolution['decision_receipt'] ?? null)
                        ? $dispute->resolution['decision_receipt']
                        : '',
                ],
                reversesMintTransactionId: $mint->id,
                arbitrationCaseId: $dispute->id,
            ));

            $clawback = new Clawback([
                'dispute_id' => $dispute->id,
                'target' => ClawbackTarget::SpecificFraudulentMint,
                'amount' => $recoverable,
                'applied_transaction_id' => $transaction->id,
            ]);
            $clawback->save();

            return $transaction;
        });
    }

    /**
     * The holder's undisputed credits: all credits received minus credits
     * from mints under any dispute not resolved in the holder's favor.
     * Mirrors the DB trigger's computation exactly.
     */
    private function undisputedCredits(Account $account): int
    {
        /** @var object{undisputed: int|string|null}|null $row */
        $row = DB::selectOne(<<<'SQL'
            SELECT
                COALESCE(SUM(e.amount) FILTER (WHERE e.amount > 0), 0)
                - COALESCE(SUM(e.amount) FILTER (
                    WHERE e.amount > 0 AND EXISTS (
                        SELECT 1 FROM attestation_disputes ad
                        WHERE ad.mint_transaction_id = e.transaction_id
                          AND ad.status <> 'resolved_valid'
                    )
                ), 0) AS undisputed
            FROM entries e
            WHERE e.account_id = ?
        SQL, [$account->id]);

        return (int) ($row->undisputed ?? 0);
    }
}
