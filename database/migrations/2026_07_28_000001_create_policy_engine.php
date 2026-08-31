<?php

/**
 * SPDX-License-Identifier: LicenseRef-MVL-2.0
 *
 * DEV-4.5 — POLICY ENGINE schema. Enforces I7 (the asymmetry) at the
 * DATABASE layer: the meridian_policy_engine role receives write access
 * ONLY to its own tables (proxy_metrics, policy_actions,
 * circuit_breakers) and to issuance_policies (FUTURE minting) — and has
 * NO INSERT/UPDATE privilege on entries or transactions. The heart
 * shapes the faucet, never the reservoir.
 *
 * © Maher
 */

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        // ------------------------------------------------------------------
        // proxy_metrics — per-credit Goodhart observation (DOCUMENT 2.3).
        // ------------------------------------------------------------------
        Schema::create('proxy_metrics', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->char('currency_id', 26);
            $table->string('declared_virtue');
            $table->decimal('measured_proxy', 16, 6);
            $table->decimal('independent_signal', 16, 6);
            $table->decimal('divergence', 16, 6)->default(0);
            $table->decimal('threshold', 16, 6);
            $table->decimal('throttle_value', 8, 6)->default(1);
            $table->timestampTz('last_evaluated')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->foreign('currency_id')->references('id')->on('currencies');
        });

        DB::unprepared(<<<'SQL'
ALTER TABLE proxy_metrics
    -- θ ∈ [0,1] (DOCUMENT 2.3 §3): a multiplier on FUTURE mint only.
    ADD CONSTRAINT proxy_metrics_throttle_range
        CHECK (throttle_value >= 0 AND throttle_value <= 1),
    ADD CONSTRAINT proxy_metrics_threshold_positive CHECK (threshold > 0);
SQL);

        // ------------------------------------------------------------------
        // policy_actions — every action versioned to the transparency log.
        // ------------------------------------------------------------------
        Schema::create('policy_actions', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->char('currency_id', 26);
            $table->string('action_type', 48);
            $table->jsonb('delta')->default('{}');
            $table->text('justification');
            $table->string('transparency_log_ref');
            $table->timestampTz('created_at')->useCurrent();

            $table->foreign('currency_id')->references('id')->on('currencies');
        });

        DB::unprepared(<<<'SQL'
ALTER TABLE policy_actions
    ADD CONSTRAINT policy_actions_type_check CHECK (action_type IN (
        'adjust_issuance_policy', 'fire_circuit_breaker', 'clear_circuit_breaker',
        'evaluate_proxy_divergence'
    ));
SQL);

        // ------------------------------------------------------------------
        // circuit_breakers — a halt is a negative, protective power.
        // ------------------------------------------------------------------
        Schema::create('circuit_breakers', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->char('currency_id', 26);
            $table->string('status', 16)->default('fired');
            $table->string('reason', 64);
            $table->timestampTz('fired_at')->useCurrent();
            $table->timestampTz('cleared_at')->nullable();

            $table->foreign('currency_id')->references('id')->on('currencies');
        });

        DB::unprepared(<<<'SQL'
ALTER TABLE circuit_breakers
    ADD CONSTRAINT circuit_breakers_status_check CHECK (status IN ('fired', 'cleared')),
    ADD CONSTRAINT circuit_breakers_clearing_shape CHECK (
        (status = 'cleared') = (cleared_at IS NOT NULL)
    );
SQL);

        // ------------------------------------------------------------------
        // I7 grants: the Policy Engine role writes its four tables and
        // NOTHING else. Its lack of privilege on entries/transactions was
        // established in DEV-4.1 (SELECT only) and is re-proven by
        // PolicyEngineNoEntryTest against the live catalog.
        // ------------------------------------------------------------------
        DB::unprepared(<<<'SQL'
GRANT SELECT, INSERT, UPDATE ON proxy_metrics, policy_actions, circuit_breakers TO meridian_policy_engine;
GRANT SELECT, INSERT, UPDATE ON proxy_metrics, policy_actions, circuit_breakers TO meridian_app;
GRANT SELECT ON proxy_metrics, policy_actions, circuit_breakers TO meridian_membrane;

REVOKE INSERT, UPDATE, DELETE, TRUNCATE ON entries, transactions FROM meridian_policy_engine;
SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('circuit_breakers');
        Schema::dropIfExists('policy_actions');
        Schema::dropIfExists('proxy_metrics');
    }
};
