<?php

/**
 * SPDX-License-Identifier: LicenseRef-MVL-2.0
 *
 * DEV-4.1 — MERIDIAN LEDGER CORE service. Enforces I1, I2, I3, I5 at the
 * service layer (the second of the three enforcement points).
 *
 * THE MINIMAL SURFACE (DOCUMENT 4.1): post(), reverse(), balance(),
 * reconcile(). There is NO update, NO delete, NO setBalance — the absence
 * of those methods is the I5/I6 guarantee at the API surface: you cannot
 * do what there is no method to do.
 *
 * This service CANNOT author an arbitration reversal: post() rejects the
 * arbitration_reversal kind outright. The only component in the entire
 * system with that capability is the Dispute Engine's single
 * applyArbitrationReversal() method (DEV-4.3), which passes through a
 * dedicated internal posting path gated by the I6-revised predicate.
 * THE ONE RULE ABOVE ALL RULES.
 *
 * © Maher
 */

declare(strict_types=1);

namespace App\Domain\Meridian\Ledger\Services;

use App\Domain\Meridian\Ledger\Data\ReconciliationResult;
use App\Domain\Meridian\Ledger\Data\TransactionDraft;
use App\Domain\Meridian\Ledger\Enums\ReversalReason;
use App\Domain\Meridian\Ledger\Enums\TransactionKind;
use App\Domain\Meridian\Ledger\Exceptions\PunitiveDebitException;
use App\Domain\Meridian\Ledger\Exceptions\UnbalancedTransactionException;
use App\Domain\Meridian\Ledger\Models\Account;
use App\Domain\Meridian\Ledger\Models\Entry;
use App\Domain\Meridian\Ledger\Models\LedgerTransaction;
use Brick\Money\Money;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class LedgerService
{
    /**
     * Validate and persist a balanced transaction atomically (I1).
     * Idempotency keys (Redis cache + durable table) make replays no-ops
     * returning the original result.
     */
    public function post(TransactionDraft $draft): LedgerTransaction
    {
        // I6 service guard: the ledger's public surface cannot author an
        // arbitration reversal. Only the Dispute Engine's internal path may.
        if ($draft->kind === TransactionKind::ArbitrationReversal) {
            throw new PunitiveDebitException(
                'I6: LedgerService::post() cannot author an arbitration reversal; '
                .'the single permitted path is DisputeService::applyArbitrationReversal().'
            );
        }

        if ($draft->reversesMintTransactionId !== null || $draft->arbitrationCaseId !== null) {
            throw new PunitiveDebitException(
                'I6: only an arbitration reversal may carry mint/case references.'
            );
        }

        return $this->persist($draft);
    }

    /**
     * Post an additive reversing transaction (I5 — never mutates the
     * original). Not usable for arbitration reversals (I6): those go
     * through the Dispute Engine only.
     *
     * I10 (Consensual Settlement): a reversal that debits a personal
     * account — i.e. the reversal of a credit the holder received — moves
     * value off the debited side and therefore REQUIRES that holder's
     * authorization, supplied as $debitedHolderAuthorizationRef. Without
     * it the database guard rejects the insert; this method does not and
     * must not manufacture consent. The only consent-free personal debit
     * in the entire system is the Dispute Engine's arbitration reversal.
     */
    public function reverse(
        LedgerTransaction $original,
        ReversalReason $reason,
        ?string $debitedHolderAuthorizationRef = null,
    ): LedgerTransaction {
        if ($reason === ReversalReason::ArbitrationReversal) {
            throw new PunitiveDebitException(
                'I6: arbitration reversals are authored only by DisputeService::applyArbitrationReversal().'
            );
        }

        $entryDrafts = [];

        foreach ($original->entries()->orderBy('id')->get() as $entry) {
            $negated = -$entry->amount;

            $entryDrafts[] = new \App\Domain\Meridian\Ledger\Data\EntryDraft(
                accountId: $entry->account_id,
                currencyId: $entry->currency_id,
                amountMinor: $negated,
                // A negated entry that is a debit needs holder consent on
                // the debited side (I10). We pass the reversal-specific
                // authorization; if the caller supplied none, the ref stays
                // null and the DB guard rejects any personal debit — the
                // guard decides, not this method.
                holderAuthorizationRef: $negated < 0
                    ? $debitedHolderAuthorizationRef
                    : null,
            );
        }

        $draft = new TransactionDraft(
            kind: TransactionKind::Reversal,
            entries: $entryDrafts,
            idempotencyKey: 'reverse:'.$original->id.':'.$reason->value,
            metadata: ['reversal_reason' => $reason->value],
            reversesTransactionId: $original->id,
        );

        return $this->persist($draft, reversesEntriesOf: $original);
    }

    /**
     * Current balance as Money (brick/money over bigint minor units).
     */
    public function balance(Account $account): Money
    {
        $account->refresh();
        $currency = $account->currency()->firstOrFail();

        return Money::ofMinor($account->balance_minor, new \Brick\Money\Currency(
            currencyCode: $currency->code,
            numericCode: 0,
            name: $currency->name,
            defaultFractionDigits: $currency->decimals,
        ));
    }

    /**
     * Recompute the balance from the full entry history and compare (I2).
     * A discrepancy is RECORDED AND ALERTED, NEVER AUTO-CORRECTED.
     */
    public function reconcile(Account $account): ReconciliationResult
    {
        $account->refresh();

        $recomputed = (int) Entry::query()
            ->where('account_id', $account->id)
            ->sum('amount');

        $consistent = $recomputed === $account->balance_minor;

        if (! $consistent) {
            DB::table('ledger_discrepancies')->insert([
                'check_kind' => 'balance_recompute',
                'account_id' => $account->id,
                'currency_id' => $account->currency_id,
                'expected_minor' => $recomputed,
                'actual_minor' => $account->balance_minor,
            ]);
        }

        return new ReconciliationResult(
            accountId: $account->id,
            storedBalanceMinor: $account->balance_minor,
            recomputedBalanceMinor: $recomputed,
            consistent: $consistent,
        );
    }

    /**
     * I3 daily materialized check: per-currency user-balance sum must equal
     * net issuance (mintedTotal − burnedTotal), expressed as: the sum of
     * ALL account balances (user + system) per currency is zero. Writes a
     * discrepancy row on inequality — alert, never auto-correct.
     *
     * @return list<string> currency ids that failed the proof
     */
    public function proveSupplyIntegrity(): array
    {
        /** @var list<object{currency_id: string, total: int|string}> $rows */
        $rows = DB::table('accounts')
            ->select('currency_id', DB::raw('SUM(balance_minor) AS total'))
            ->groupBy('currency_id')
            ->havingRaw('SUM(balance_minor) <> 0')
            ->get()
            ->all();

        $failures = [];

        foreach ($rows as $row) {
            $failures[] = $row->currency_id;
            DB::table('ledger_discrepancies')->insert([
                'check_kind' => 'supply_proof',
                'currency_id' => $row->currency_id,
                'expected_minor' => 0,
                'actual_minor' => (int) $row->total,
            ]);
        }

        return $failures;
    }

    /**
     * The single internal persistence path. Protected so that ONLY the
     * Dispute Engine (which extends this class into its arbitration-scoped
     * poster) can reach it with an arbitration_reversal kind — and even
     * then the DB trigger re-checks the full I6-revised predicate.
     */
    protected function persist(TransactionDraft $draft, ?LedgerTransaction $reversesEntriesOf = null): LedgerTransaction
    {
        // I1 service guard: reject unbalanced drafts before any write.
        if (! $draft->isBalanced()) {
            throw new UnbalancedTransactionException(
                'I1: transaction entries must sum to zero per currency; got '.json_encode($draft->perCurrencySums(), JSON_THROW_ON_ERROR)
            );
        }

        if ($draft->entries === []) {
            throw new UnbalancedTransactionException('I1: a transaction must carry at least two entries.');
        }

        // Idempotency: Redis fast path.
        $cacheKey = 'ledger:idem:'.$draft->idempotencyKey;
        $existingId = Cache::get($cacheKey);

        if (is_string($existingId)) {
            return LedgerTransaction::query()->findOrFail($existingId);
        }

        // Idempotency: durable table path.
        $existing = LedgerTransaction::query()
            ->where('idempotency_key', $draft->idempotencyKey)
            ->first();

        if ($existing !== null) {
            Cache::put($cacheKey, $existing->id, now()->addDay());

            return $existing;
        }

        try {
            $transaction = DB::transaction(function () use ($draft, $reversesEntriesOf): LedgerTransaction {
                $transaction = new LedgerTransaction([
                    'id' => (string) Str::ulid(),
                    'kind' => $draft->kind,
                    'idempotency_key' => $draft->idempotencyKey,
                    'metadata' => $draft->metadata,
                    'reverses_transaction_id' => $draft->reversesTransactionId,
                    'reverses_mint_transaction_id' => $draft->reversesMintTransactionId,
                    'arbitration_case_id' => $draft->arbitrationCaseId,
                ]);
                $transaction->save();

                $originalEntries = $reversesEntriesOf?->entries()->orderBy('id')->get();

                foreach ($draft->entries as $index => $entryDraft) {
                    $entry = new Entry([
                        'transaction_id' => $transaction->id,
                        'account_id' => $entryDraft->accountId,
                        'currency_id' => $entryDraft->currencyId,
                        'amount' => $entryDraft->amountMinor,
                        'balance_after' => 0, // computed by trg_entries_balance_after
                        'holder_authorization_ref' => $entryDraft->holderAuthorizationRef,
                        'reverses_entry_id' => $originalEntries?->get($index)?->id,
                    ]);
                    $entry->save();
                }

                DB::table('idempotency_keys')->insert([
                    'key' => $draft->idempotencyKey,
                    'transaction_id' => $transaction->id,
                ]);

                return $transaction;
            });
        } catch (\Illuminate\Database\UniqueConstraintViolationException) {
            // Concurrent replay lost the race: return the original result.
            return LedgerTransaction::query()
                ->where('idempotency_key', $draft->idempotencyKey)
                ->firstOrFail();
        }

        Cache::put($cacheKey, $transaction->id, now()->addDay());

        return $transaction->refresh();
    }
}
