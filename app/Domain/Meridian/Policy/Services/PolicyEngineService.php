<?php

/**
 * SPDX-License-Identifier: LicenseRef-MVL-2.0
 *
 * DEV-4.5 — POLICY ENGINE service. The heart: it circulates macro
 * adjustment outward but has no hands.
 *
 * I7 AT THE SERVICE LAYER — THE TYPED ARCHITECTURAL BOUNDARY: this class
 * (and everything under App\Domain\Meridian\Policy) has NO dependency on
 * and NO compile-time access to the ledger-write path. It does not
 * import the ledger service or its draft value objects. Its only write
 * kinds are: issuance_policies (FUTURE minting), proxy_metrics,
 * policy_actions, circuit_breakers. PolicyEngineNoEntryTest proves this
 * both architecturally (import scan) and at runtime (no action across
 * all types ever produces an entry), and the meridian_policy_engine DB
 * role independently lacks the privilege.
 *
 * The anti-Goodhart throttle (DOCUMENT 2.3 §3): θ_c(t) = f(D_c(t)),
 * monotone-decreasing, f(D ≤ D*) = 1, f → 0 as divergence grows. θ
 * multiplies the FUTURE mint cap and appears in no term affecting
 * existing balances — the faucet, never the reservoir.
 *
 * © Maher
 */

declare(strict_types=1);

namespace App\Domain\Meridian\Policy\Services;

use App\Domain\Meridian\Issuance\Models\IssuancePolicy;
use App\Domain\Meridian\Ledger\Models\Currency;
use App\Domain\Meridian\Policy\Data\MacroState;
use App\Domain\Meridian\Policy\Data\PolicyDelta;
use App\Domain\Meridian\Policy\Data\ThrottleDecision;
use App\Domain\Meridian\Policy\Enums\BreakerReason;
use App\Domain\Meridian\Policy\Enums\PolicyActionType;
use App\Domain\Meridian\Policy\Models\CircuitBreaker;
use App\Domain\Meridian\Policy\Models\PolicyAction;
use App\Domain\Meridian\Policy\Models\ProxyMetric;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PolicyEngineService
{
    /**
     * Read aggregate flows and signals. Observation writes nothing.
     */
    public function observe(): MacroState
    {
        /** @var list<object{currency_id: string, outstanding: int|string}> $supplies */
        $supplies = DB::select(<<<'SQL'
            SELECT a.currency_id, -SUM(a.balance_minor) AS outstanding
            FROM accounts a
            WHERE a.system_role = 'issuance'
            GROUP BY a.currency_id
        SQL);

        $outstanding = [];
        foreach ($supplies as $row) {
            $outstanding[$row->currency_id] = (int) $row->outstanding;
        }

        $throttles = [];
        foreach (ProxyMetric::query()->get() as $metric) {
            $throttles[$metric->currency_id] = (float) $metric->throttle_value;
        }

        /** @var list<string> $fired */
        $fired = CircuitBreaker::query()
            ->where('status', 'fired')
            ->pluck('currency_id')
            ->unique()
            ->filter(static fn (mixed $id): bool => is_string($id))
            ->values()
            ->all();

        return new MacroState(
            outstandingSupplyByCurrency: $outstanding,
            throttleByCurrency: $throttles,
            firedBreakerCurrencyIds: $fired,
            observedAtUnix: now()->unix(),
        );
    }

    /**
     * Write FUTURE-issuance parameters — the ONLY kind of write the
     * Policy Engine can make (I7). Bounded by the per-epoch movement cap.
     */
    public function adjustIssuancePolicy(Currency $currency, PolicyDelta $delta): IssuancePolicy
    {
        if ($delta->exceedsMovementCap()) {
            throw new \DomainException(
                'I7 damage bound: the delta exceeds the per-epoch movement cap of '
                .PolicyDelta::MAX_EPOCH_MOVEMENT.'; a corrupted signal can move policy '
                .'by at most the capped step.'
            );
        }

        $policy = IssuancePolicy::query()
            ->where('currency_id', $currency->id)
            ->firstOrFail();

        return DB::transaction(function () use ($currency, $policy, $delta): IssuancePolicy {
            $rateLimit = $policy->rate_limit ?? [];

            if ($delta->rateLimitMultiplier !== null) {
                $current = is_numeric($rateLimit['epoch_cap_multiplier'] ?? null)
                    ? (float) $rateLimit['epoch_cap_multiplier']
                    : 1.0;
                $rateLimit['epoch_cap_multiplier'] = round($current * $delta->rateLimitMultiplier, 6);
            }

            $policy->rate_limit = $rateLimit;

            if ($delta->newMaxSupply !== null) {
                $policy->max_supply = $delta->newMaxSupply;
            }

            $policy->save();

            $this->logAction($currency->id, PolicyActionType::AdjustIssuancePolicy, [
                'rate_limit_multiplier' => $delta->rateLimitMultiplier,
                'new_max_supply' => $delta->newMaxSupply,
            ], $delta->justification);

            return $policy;
        });
    }

    /**
     * Halt a currency's automatic issuance/conversion on anomaly. A halt
     * is a negative, protective power: refuse and halt, never command.
     */
    public function fireCircuitBreaker(Currency $currency, BreakerReason $reason): CircuitBreaker
    {
        return DB::transaction(function () use ($currency, $reason): CircuitBreaker {
            $breaker = new CircuitBreaker([
                'currency_id' => $currency->id,
                'status' => 'fired',
                'reason' => $reason,
            ]);
            $breaker->save();

            $this->logAction($currency->id, PolicyActionType::FireCircuitBreaker, [
                'reason' => $reason->value,
            ], 'Automatic protective halt: '.$reason->value);

            return $breaker;
        });
    }

    /**
     * Compute the per-credit Goodhart throttle θ (DOCUMENT 2.3 §3) and
     * store it on the proxy metric, where the Issuance Engine reads it
     * as a FUTURE mint-cap multiplier. No θ term touches any balance.
     */
    public function evaluateProxyDivergence(Currency $currency): ThrottleDecision
    {
        $metric = ProxyMetric::query()
            ->where('currency_id', $currency->id)
            ->firstOrFail();

        $divergence = abs((float) $metric->measured_proxy - (float) $metric->independent_signal);
        $threshold = (float) $metric->threshold;

        // f(D ≤ D*) = 1; monotone-decreasing linear falloff to 0 beyond
        // 2·D* (simple, calibratable in the simulation sandbox).
        $theta = $divergence <= $threshold
            ? 1.0
            : max(0.0, 1.0 - ($divergence - $threshold) / $threshold);

        return DB::transaction(function () use ($currency, $metric, $divergence, $threshold, $theta): ThrottleDecision {
            $metric->divergence = (string) $divergence;
            $metric->throttle_value = (string) $theta;
            $metric->last_evaluated = now();
            $metric->save();

            $this->logAction($currency->id, PolicyActionType::EvaluateProxyDivergence, [
                'divergence' => $divergence,
                'threshold' => $threshold,
                'theta' => $theta,
            ], 'Anti-Goodhart evaluation: θ multiplies future mint only.');

            if ($divergence > 2 * $threshold) {
                $this->fireCircuitBreaker($currency, BreakerReason::DivergenceSpike);
            }

            return new ThrottleDecision(
                currencyId: $currency->id,
                divergence: $divergence,
                threshold: $threshold,
                theta: $theta,
            );
        });
    }

    /** @param array<string, mixed> $delta */
    private function logAction(string $currencyId, PolicyActionType $type, array $delta, string $justification): void
    {
        $action = new PolicyAction([
            'currency_id' => $currencyId,
            'action_type' => $type,
            'delta' => $delta,
            'justification' => $justification,
            'transparency_log_ref' => 'tlog:'.Str::ulid(),
        ]);
        $action->save();
    }
}
