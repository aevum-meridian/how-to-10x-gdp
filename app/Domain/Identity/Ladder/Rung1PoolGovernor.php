<?php

/**
 * SPDX-License-Identifier: LicenseRef-MVL-2.0 AND LicenseRef-AVL-2.0
 *
 * DEV-6.x / DOCUMENT 6.2 §2 — the hard constitutional cap on the
 * probationary Rung-1 pool.
 *
 * The ladder's honest resolution of the inclusion-vs-Sybil trade-off is
 * to BOUND the loss, not to pretend it solved: Rung-1 is the rung for
 * those without documents or devices, and its web-of-trust verification
 * is the most fragile — so the maximum exposure to Sybil farming at
 * Rung-1 is a ConstitutionalParameter, fixed and known, amendable only
 * by the constitutional process.
 *
 * Twice enforced here (the third layer is the property test):
 *  - service guard: computes issued + grant against the cap and refuses;
 *  - DB trigger rung1_pool_guard_cap(): recomputes the same predicate
 *    under an advisory lock, so a writer that bypasses this class is
 *    still stopped.
 *
 * This class records ENTITLEMENT bookkeeping only. It holds no ledger
 * capability: actual value movement flows through the Issuance Engine's
 * quorum-minting path, which independently enforces I4/I8/I11.
 * © Maher
 */

declare(strict_types=1);

namespace App\Domain\Identity\Ladder;

use App\Domain\Identity\Enums\AssuranceRung;
use App\Domain\Identity\Exceptions\ConstitutionalCapException;
use App\Domain\Identity\Models\ConstitutionalParameter;
use App\Domain\Identity\Models\Identity;
use App\Domain\Identity\Models\Rung1PoolGrant;
use Illuminate\Support\Facades\DB;

final class Rung1PoolGovernor
{
    public const CAP_PARAMETER_KEY = 'rung1_pool_cap_minor';

    /**
     * Grant from the probationary pool. Refuses beyond the cap; fails
     * CLOSED when no cap is defined. Idempotent by key.
     */
    public function grant(Identity $identity, int $amountMinor, string $idempotencyKey): Rung1PoolGrant
    {
        if ($amountMinor <= 0) {
            throw new \InvalidArgumentException('RUNG-1 POOL: a grant must be a positive amount.');
        }

        if ($identity->effective_rung < AssuranceRung::Rung1->value) {
            throw new ConstitutionalCapException(
                'RUNG-1 POOL: the probationary pool is for Rung-1-and-above identities; '
                .'a Rung-0 wallet may hold, swap, and transfer, but the dividend pool requires social attestation.'
            );
        }

        $existing = Rung1PoolGrant::query()->where('idempotency_key', $idempotencyKey)->first();

        if ($existing !== null) {
            return $existing;
        }

        return DB::transaction(function () use ($identity, $amountMinor, $idempotencyKey): Rung1PoolGrant {
            // Same advisory lock the DB trigger takes: one shared budget.
            DB::unprepared("SELECT pg_advisory_xact_lock(hashtext('rung1_pool_cap'))");

            $cap = ConstitutionalParameter::query()->find(self::CAP_PARAMETER_KEY);

            if ($cap === null) {
                throw new ConstitutionalCapException(
                    'RUNG-1 POOL: no constitutional cap is defined; the pool fails CLOSED.'
                );
            }

            $issued = (int) Rung1PoolGrant::query()->sum('amount_minor');

            if ($issued + $amountMinor > $cap->value_minor) {
                throw new ConstitutionalCapException(sprintf(
                    'RUNG-1 POOL: grant of %d would exceed the hard constitutional cap (issued %d, cap %d). '
                    .'The cap is the bound on Sybil damage; it is amendable only by the constitutional process.',
                    $amountMinor,
                    $issued,
                    $cap->value_minor,
                ));
            }

            $grant = new Rung1PoolGrant([
                'identity_id' => $identity->id,
                'amount_minor' => $amountMinor,
                'idempotency_key' => $idempotencyKey,
                'granted_at' => now(),
            ]);
            $grant->save();

            return $grant;
        });
    }

    /**
     * The remaining budget — public, so the bound on exposure is
     * checkable by anyone.
     */
    public function remainingBudgetMinor(): int
    {
        $cap = ConstitutionalParameter::query()->find(self::CAP_PARAMETER_KEY);

        if ($cap === null) {
            return 0; // fail closed
        }

        return max(0, $cap->value_minor - (int) Rung1PoolGrant::query()->sum('amount_minor'));
    }
}
