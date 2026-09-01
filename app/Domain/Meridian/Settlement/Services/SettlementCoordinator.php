<?php

/**
 * SPDX-License-Identifier: LicenseRef-MVL-2.0
 *
 * DEV-7.x — THE HANDS: atomic both-commit-or-both-abort settlement
 * (DOCUMENT 7.x). Grasps but cannot punch:
 *
 *  1. Holder authorization is verified on EVERY debited personal
 *     account BEFORE any commit begins (I10) — a settlement missing
 *     one authorization never starts writing at all.
 *  2. All legs post inside ONE database transaction; any failure at
 *     any point aborts the whole — the abort is a rollback that
 *     issues no entries, so there is physically no partial state.
 *  3. Post-rollback, the coordinator VERIFIES no entry persisted
 *     against any account in the settlement (the service-layer half
 *     of the abort-path guarantee; the DB half is atomicity itself).
 *
 * Inheritance of I6/I7: this class has no method to punitively debit
 * anything — the only personal-contribution debit in the whole system
 * remains the Dispute Engine's single reversal poster (DEV-4.3). The value
 * movement here is a balanced Ledger Core posting like any other; the
 * coordinator coordinates, the Ledger Core posts, and every invariant
 * guard and trigger beneath post() applies unchanged.
 *
 * The failure-injection hook ($chaos) exists so the abort path can be
 * adversarially tested: a malicious implementer would hide a punitive
 * debit exactly there, and SettlementAbortTest verifies they could
 * not.
 *
 * © Maher
 */

declare(strict_types=1);

namespace App\Domain\Meridian\Settlement\Services;

use App\Domain\Meridian\Ledger\Data\EntryDraft;
use App\Domain\Meridian\Ledger\Data\TransactionDraft;
use App\Domain\Meridian\Ledger\Enums\TransactionKind;
use App\Domain\Meridian\Ledger\Services\LedgerService;
use App\Domain\Meridian\Settlement\Data\SettlementLeg;
use App\Domain\Meridian\Settlement\Data\SettlementResult;
use App\Domain\Meridian\Settlement\Exceptions\SettlementAbortedException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

final class SettlementCoordinator
{
    public function __construct(
        private readonly LedgerService $ledger,
    ) {
    }

    /**
     * Settle a set of legs atomically: all commit, or none exist.
     *
     * @param list<SettlementLeg> $legs
     * @param \Closure(int): void|null $chaos failure-injection hook,
     *        called after each leg posts (test-only adversarial hook)
     *
     * @throws SettlementAbortedException on any failure — after which
     *         the ledger is bit-for-bit what it was before the call
     */
    public function settle(string $settlementRef, array $legs, ?\Closure $chaos = null): SettlementResult
    {
        if ($legs === []) {
            throw new \InvalidArgumentException('A settlement requires at least one leg.');
        }

        // ------------------------------------------------------------
        // Phase 1 — I10 pre-verification, BEFORE any write: every
        // debited personal account must present holder authorization.
        // ------------------------------------------------------------
        foreach ($legs as $index => $leg) {
            /** @var object{owner_type: string}|null $debited */
            $debited = DB::selectOne(
                'SELECT owner_type FROM accounts WHERE id = ?',
                [$leg->fromAccountId],
            );

            if ($debited === null) {
                throw new SettlementAbortedException(
                    "Settlement {$settlementRef} leg {$index}: debited account does not exist."
                );
            }

            if ($debited->owner_type === 'person' && $leg->holderAuthorizationRef === null) {
                throw new SettlementAbortedException(
                    "I10: settlement {$settlementRef} leg {$index} debits a personal "
                    .'account without holder authorization; the settlement never starts.'
                );
            }
        }

        // ------------------------------------------------------------
        // Phase 2 — atomic commit: every leg posts inside ONE database
        // transaction. Any exception rolls back EVERYTHING.
        // ------------------------------------------------------------
        $idempotencyKeys = [];

        try {
            $transactionIds = DB::transaction(function () use ($settlementRef, $legs, $chaos, &$idempotencyKeys): array {
                $ids = [];

                foreach ($legs as $index => $leg) {
                    $idempotencyKey = "settle:{$settlementRef}:leg:{$index}";
                    $idempotencyKeys[] = $idempotencyKey;

                    $draft = new TransactionDraft(
                        kind: TransactionKind::Settlement,
                        entries: [
                            new EntryDraft(
                                accountId: $leg->fromAccountId,
                                currencyId: $leg->currencyId,
                                amountMinor: -$leg->amountMinor,
                                holderAuthorizationRef: $leg->holderAuthorizationRef,
                            ),
                            new EntryDraft(
                                accountId: $leg->toAccountId,
                                currencyId: $leg->currencyId,
                                amountMinor: $leg->amountMinor,
                            ),
                        ],
                        idempotencyKey: $idempotencyKey,
                        metadata: ['settlement_ref' => $settlementRef, 'leg' => $index],
                    );

                    $ids[] = $this->ledger->post($draft)->id;

                    // Adversarial hook: coordinator failure, timeout,
                    // partition — injected AFTER a leg posted, i.e. at
                    // the exact point a partial state would exist if
                    // atomicity did not hold.
                    if ($chaos !== null) {
                        $chaos($index);
                    }
                }

                return $ids;
            });
        } catch (\Throwable $e) {
            // ------------------------------------------------------------
            // Phase 3 — the abort path. The rollback already happened
            // (DB::transaction re-throws after ROLLBACK). What remains:
            //
            //  (a) forget any idempotency cache the inner posts wrote —
            //      those ids no longer exist and must not poison replays;
            //  (b) VERIFY no entry persisted (the service-layer half of
            //      the abort-path guarantee);
            //  (c) surface the abort. NOTHING here writes to the ledger:
            //      the abort path contains zero posting capability.
            // ------------------------------------------------------------
            foreach ($idempotencyKeys as $key) {
                Cache::forget('ledger:idem:'.$key);
            }

            $this->assertNothingPersisted($settlementRef);

            throw new SettlementAbortedException(
                "Settlement {$settlementRef} aborted: {$e->getMessage()} "
                .'Prior state is restored exactly; no personal balance was net-debited.',
                previous: $e,
            );
        }

        return new SettlementResult(
            settlementRef: $settlementRef,
            transactionIds: $transactionIds,
        );
    }

    /**
     * The post-rollback verification: not one transaction or entry of
     * this settlement survived the abort.
     */
    private function assertNothingPersisted(string $settlementRef): void
    {
        $survivors = (int) DB::table('transactions')
            ->where('idempotency_key', 'like', "settle:{$settlementRef}:%")
            ->count();

        if ($survivors !== 0) {
            // This would be a launch-blocking defect (proof 5.3 trace
            // format): an abort that left state behind.
            throw new \LogicException(
                "ABORT-PATH DEFECT: settlement {$settlementRef} aborted but "
                ."{$survivors} transaction(s) persisted. This violates the "
                .'abort-path guarantee and must block launch.'
            );
        }
    }
}
