<?php

/**
 * SPDX-License-Identifier: LicenseRef-MVL-2.0 AND LicenseRef-AVL-2.0
 *
 * DEV-7.2 — how the two legs keep each other honest (DOCUMENT 7.2).
 *
 * Proves every Aevum-believed committed fact (confirmation.committed
 * event) maps to a posted, balanced Meridian transaction, and every
 * ingress-originated Meridian transaction maps back to a proposal.
 * Comparison is on ECONOMIC FACTS (ids, amounts, accounts, currencies)
 * — never PII — so it survives crypto-shredding (DOCUMENT 6.5).
 *
 * On drift it raises a hash-chained reconciliation.alert into the
 * event stream and STOPS. It NEVER silently auto-corrects: this class
 * contains no balance-adjusting capability of any kind — an
 * auto-correcting reconciler is itself the back door that lets a
 * compromised component "fix" the books to hide theft. Corrections,
 * if warranted after human investigation, are additive reversing
 * entries posted by humans through the normal ledger path (I5).
 *
 * © Maher
 */

declare(strict_types=1);

namespace App\Domain\Joint\Reconciliation\Services;

use App\Domain\Joint\EventContract\Enums\EventKind;
use App\Domain\Joint\EventContract\Enums\EventSource;
use App\Domain\Joint\EventContract\Models\CrossSystemEvent;
use App\Domain\Joint\EventContract\Services\EventChainService;
use App\Domain\Joint\Reconciliation\Data\ReconciliationReport;
use Illuminate\Support\Facades\DB;

final class ReconciliationService
{
    public function __construct(
        private readonly EventChainService $chain,
    ) {
    }

    /**
     * Run one reconciliation cycle. $meridianSecretKey signs any
     * alerts raised (the fact that a drift was detected is itself
     * tamper-evident on the chain).
     */
    public function reconcile(string $meridianSecretKey): ReconciliationReport
    {
        /** @var list<array{type: string, event_id?: string, transaction_id?: string, detail: string}> $drifts */
        $drifts = [];

        // ------------------------------------------------------------
        // Direction 1: every confirmation.committed → a posted,
        // balanced Meridian transaction (Aevum's belief → the fact).
        // ------------------------------------------------------------
        $confirmations = CrossSystemEvent::query()
            ->where('kind', EventKind::ConfirmationCommitted->value)
            ->orderBy('seq')
            ->get();

        foreach ($confirmations as $confirmation) {
            $transactionId = $confirmation->payload['transaction_id'] ?? null;

            if (! is_string($transactionId) || $transactionId === '') {
                $drifts[] = [
                    'type' => 'malformed_confirmation',
                    'event_id' => $confirmation->id,
                    'detail' => 'confirmation.committed carries no transaction_id',
                ];

                continue;
            }

            /** @var object{id: string}|null $transaction */
            $transaction = DB::selectOne(
                'SELECT id FROM transactions WHERE id = ?',
                [$transactionId],
            );

            if ($transaction === null) {
                $drifts[] = [
                    'type' => 'believed_but_unposted',
                    'event_id' => $confirmation->id,
                    'transaction_id' => $transactionId,
                    'detail' => 'Aevum believes a value movement occurred that the ledger never posted',
                ];

                continue;
            }

            // The economic fact must balance per currency (I1 restated
            // from the independent record — reconciliation trusts no
            // one, including the trigger that already enforced it).
            /** @var object{unbalanced: int} $balance */
            $balance = DB::selectOne(<<<'SQL'
                SELECT count(*) AS unbalanced FROM (
                    SELECT currency_id
                    FROM entries
                    WHERE transaction_id = ?
                    GROUP BY currency_id
                    HAVING sum(amount) <> 0
                ) AS broken
            SQL, [$transactionId]);

            if ((int) $balance->unbalanced !== 0) {
                $drifts[] = [
                    'type' => 'unbalanced_fact',
                    'event_id' => $confirmation->id,
                    'transaction_id' => $transactionId,
                    'detail' => 'the matched transaction does not balance per currency',
                ];
            }
        }

        // ------------------------------------------------------------
        // Direction 2: every ingress-originated Meridian transaction →
        // an Aevum proposal event (the fact → its attribution).
        // ------------------------------------------------------------
        /** @var list<object{id: string, proposal_event_id: string|null}> $ingressTransactions */
        $ingressTransactions = DB::select(<<<'SQL'
            SELECT id, metadata->>'proposal_event_id' AS proposal_event_id
            FROM transactions
            WHERE metadata ?? 'proposal_event_id'
            ORDER BY posted_at
        SQL);

        foreach ($ingressTransactions as $row) {
            $proposalEventId = $row->proposal_event_id;

            $proposal = is_string($proposalEventId) && $proposalEventId !== ''
                ? CrossSystemEvent::query()
                    ->where('id', $proposalEventId)
                    ->where('source', EventSource::Aevum->value)
                    ->first()
                : null;

            if ($proposal === null) {
                $drifts[] = [
                    'type' => 'posted_but_unattributed',
                    'transaction_id' => $row->id,
                    'detail' => 'a ledger transaction claims ingress origin but no matching Aevum proposal exists',
                ];
            }
        }

        // ------------------------------------------------------------
        // Alert — never correct. Each drift becomes a hash-chained
        // reconciliation.alert routed to the incident process.
        // ------------------------------------------------------------
        $alertEventIds = [];

        foreach ($drifts as $index => $drift) {
            $alert = $this->chain->append(
                source: EventSource::Meridian,
                kind: EventKind::ReconciliationAlert,
                payload: $drift,
                idempotencyKey: 'recon-alert:'.hash('sha256', $this->chain->canonicalize($drift)),
                secretKey: $meridianSecretKey,
            );

            $alertEventIds[] = $alert->event->id;
        }

        return new ReconciliationReport(
            confirmationsChecked: $confirmations->count(),
            transactionsChecked: count($ingressTransactions),
            drifts: $drifts,
            alertEventIds: $alertEventIds,
        );
    }
}
