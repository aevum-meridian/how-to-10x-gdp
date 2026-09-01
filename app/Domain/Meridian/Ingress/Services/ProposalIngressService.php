<?php

/**
 * SPDX-License-Identifier: LicenseRef-MVL-2.0
 *
 * DEV-7.1 — Meridian's SINGLE ingress: ReceiveProposal (the ingress
 * modeled in proof 5.3). The heart of failure isolation:
 *
 *   MERIDIAN VALIDATES, NEVER TRUSTS.
 *
 * Every proposal — however signed, however chained — passes the full
 * invariant gate before any entry exists: I1 (balance) and I10
 * (holder authorization on personal debits) via the Ledger Core's
 * service guards AND the DB triggers beneath them; I6 (no punitive
 * debit) because the ingress posts through LedgerService::post(),
 * which structurally cannot author an arbitration reversal; I4/I11
 * because minting is not in the ingress vocabulary at all — a
 * proposal cannot ask for a mint; issuance has its own quorum-gated
 * engine.
 *
 * A fully compromised Aevum therefore produces rejected proposals and
 * corrupt experience — never a single invalid Meridian entry. The
 * outcome, either way, flows back as a signed confirmation event on
 * the same chain.
 *
 * © Maher
 */

declare(strict_types=1);

namespace App\Domain\Meridian\Ingress\Services;

use App\Domain\Joint\EventContract\Enums\EventKind;
use App\Domain\Joint\EventContract\Enums\EventSource;
use App\Domain\Joint\EventContract\Enums\EventStatus;
use App\Domain\Joint\EventContract\Exceptions\ChainIntegrityException;
use App\Domain\Joint\EventContract\Models\CrossSystemEvent;
use App\Domain\Joint\EventContract\Services\EventChainService;
use App\Domain\Meridian\Ingress\Data\IngressOutcome;
use App\Domain\Meridian\Ledger\Data\EntryDraft;
use App\Domain\Meridian\Ledger\Data\TransactionDraft;
use App\Domain\Meridian\Ledger\Enums\TransactionKind;
use App\Domain\Meridian\Ledger\Services\LedgerService;
use Illuminate\Support\Facades\DB;

final class ProposalIngressService
{
    public function __construct(
        private readonly EventChainService $chain,
        private readonly LedgerService $ledger,
    ) {
    }

    /**
     * Receive one proposal event. Idempotent: a proposal already
     * carrying a terminal outcome returns that outcome unchanged —
     * a replay cannot double-commit.
     *
     * $meridianSecretKey signs the confirmation Meridian emits back.
     */
    public function receiveProposal(CrossSystemEvent $proposal, string $meridianSecretKey): IngressOutcome
    {
        // Only proposals enter here, and only Aevum proposes.
        if (! $proposal->kind->isProposal()) {
            throw new \InvalidArgumentException(
                'INGRESS: only proposal events may enter ReceiveProposal; '
                ."{$proposal->kind->value} is not a proposal."
            );
        }

        if ($proposal->source !== EventSource::Aevum) {
            throw new \InvalidArgumentException(
                'INGRESS: proposals flow Aevum → Meridian; a Meridian-sourced '
                .'proposal is a category error.'
            );
        }

        // Idempotent replay: a terminal outcome is returned, not redone.
        $fresh = CrossSystemEvent::query()->findOrFail($proposal->id);
        if ($fresh->status === EventStatus::Committed || $fresh->status === EventStatus::Rejected) {
            $confirmation = $this->findExistingConfirmation($fresh);

            return new IngressOutcome(
                committed: $fresh->status === EventStatus::Committed,
                transactionId: $fresh->result_transaction_id,
                rejectionReason: $fresh->rejection_reason,
                confirmation: $confirmation,
            );
        }

        // Authenticity: hash recomputes, signature verifies. A forged
        // event is refused before validation even begins.
        $this->chain->assertAuthentic($fresh);

        // Validate → commit or reject. Every rejection reason is the
        // isolation property speaking.
        try {
            $transactionId = $this->validateAndCommit($fresh);

            DB::table('cross_system_events')->where('id', $fresh->id)->update([
                'status' => EventStatus::Committed->value,
                'result_transaction_id' => $transactionId,
            ]);

            $confirmation = $this->chain->append(
                source: EventSource::Meridian,
                kind: EventKind::ConfirmationCommitted,
                payload: [
                    'proposal_event_id' => $fresh->id,
                    'transaction_id' => $transactionId,
                ],
                idempotencyKey: 'confirm:'.$fresh->id,
                secretKey: $meridianSecretKey,
            )->event;

            return new IngressOutcome(
                committed: true,
                transactionId: $transactionId,
                rejectionReason: null,
                confirmation: $confirmation,
            );
        } catch (\DomainException|\InvalidArgumentException|\Illuminate\Database\QueryException $e) {
            $reason = $this->rejectionReason($e);

            DB::table('cross_system_events')->where('id', $fresh->id)->update([
                'status' => EventStatus::Rejected->value,
                'rejection_reason' => $reason,
            ]);

            $confirmation = $this->chain->append(
                source: EventSource::Meridian,
                kind: EventKind::ConfirmationRejected,
                payload: [
                    'proposal_event_id' => $fresh->id,
                    'reason' => $reason,
                ],
                idempotencyKey: 'confirm:'.$fresh->id,
                secretKey: $meridianSecretKey,
            )->event;

            return new IngressOutcome(
                committed: false,
                transactionId: null,
                rejectionReason: $reason,
                confirmation: $confirmation,
            );
        }
    }

    /**
     * The validation gate. The ingress vocabulary is deliberately
     * narrow: only proposal.transfer moves value, and it moves value
     * ONLY through the Ledger Core's post() — every invariant guard
     * and trigger beneath it applies unchanged.
     */
    private function validateAndCommit(CrossSystemEvent $proposal): string
    {
        if ($proposal->kind !== EventKind::ProposalTransfer) {
            throw new \DomainException(
                "INGRESS: {$proposal->kind->value} carries no value movement; "
                .'nothing to commit against the ledger.'
            );
        }

        $p = $proposal->payload;

        $fromAccountId = $this->requireString($p, 'from_account_id');
        $toAccountId = $this->requireString($p, 'to_account_id');
        $currencyId = $this->requireString($p, 'currency_id');
        $amountMinor = $p['amount_minor'] ?? null;
        $authorizationRef = $p['holder_authorization_ref'] ?? null;

        if (! is_int($amountMinor) || $amountMinor <= 0) {
            throw new \DomainException('INGRESS: amount_minor must be a positive integer.');
        }

        if ($authorizationRef !== null && ! is_string($authorizationRef)) {
            throw new \DomainException('INGRESS: holder_authorization_ref must be a string when present.');
        }

        // Sufficient funds: a proposal to spend value the account does
        // not hold is economically invalid — validated here because a
        // personal account must never be pushed negative by a proposal.
        /** @var object{balance_minor: int, owner_type: string}|null $from */
        $from = DB::selectOne(
            'SELECT balance_minor, owner_type FROM accounts WHERE id = ?',
            [$fromAccountId],
        );

        if ($from === null) {
            throw new \DomainException('INGRESS: the debited account does not exist.');
        }

        if ($from->owner_type === 'person' && (int) $from->balance_minor < $amountMinor) {
            throw new \DomainException(
                'INGRESS: insufficient funds — the proposal would overdraw a personal '
                ."account (balance {$from->balance_minor}, requested {$amountMinor}). "
                .'A compromised proposer cannot spend value that does not exist.'
            );
        }

        // Balanced pair; the debit carries whatever authorization the
        // proposal presented. If the debited account is personal and
        // the ref is missing or invalid, I10 rejects — service AND DB.
        $draft = new TransactionDraft(
            kind: TransactionKind::Transfer,
            entries: [
                new EntryDraft(
                    accountId: $fromAccountId,
                    currencyId: $currencyId,
                    amountMinor: -$amountMinor,
                    holderAuthorizationRef: is_string($authorizationRef) ? $authorizationRef : null,
                ),
                new EntryDraft(
                    accountId: $toAccountId,
                    currencyId: $currencyId,
                    amountMinor: $amountMinor,
                ),
            ],
            idempotencyKey: 'ingress:'.$proposal->id,
            metadata: [
                'proposal_event_id' => $proposal->id,
                'proposal_idempotency_key' => $proposal->idempotency_key,
            ],
        );

        return $this->ledger->post($draft)->id;
    }

    private function findExistingConfirmation(CrossSystemEvent $proposal): CrossSystemEvent
    {
        return CrossSystemEvent::query()
            ->where('idempotency_key', 'confirm:'.$proposal->id)
            ->firstOrFail();
    }

    /** @param array<string, mixed> $payload */
    private function requireString(array $payload, string $key): string
    {
        $value = $payload[$key] ?? null;

        if (! is_string($value) || $value === '') {
            throw new \DomainException("INGRESS: {$key} is required and must be a non-empty string.");
        }

        return $value;
    }

    private function rejectionReason(\Throwable $e): string
    {
        if ($e instanceof ChainIntegrityException) {
            return 'chain: '.$e->getMessage();
        }

        // Surface the invariant identifier (I1/I6/I10/…) the guard or
        // trigger raised — the reason is part of the audit record.
        return mb_substr($e->getMessage(), 0, 900);
    }
}
