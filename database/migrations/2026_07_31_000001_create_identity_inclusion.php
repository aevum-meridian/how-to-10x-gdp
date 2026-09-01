<?php

/**
 * SPDX-License-Identifier: LicenseRef-MVL-2.0 AND LicenseRef-AVL-2.0
 *
 * DEV-6.x — IDENTITY, PRIVACY, INCLUSION (DOCUMENTS 6.1/6.2/6.3/6.4).
 *
 * Load-bearing DB-layer constraints:
 *
 *  - Rung-1 pool HARD CONSTITUTIONAL CAP (DOCUMENT 6.2 §2): trigger
 *    rung1_pool_guard_cap() refuses any grant that would push the pool's
 *    lifetime issuance past the ConstitutionalParameter cap. The cap row
 *    itself is guarded: its value cannot change without a NEW, non-empty
 *    amendment reference (the constitutional process leaves a mark).
 *
 *  - Social recovery (DOCUMENT 6.2 §3): trigger recovery_guard_completion()
 *    refuses completion before the timelocked challenge window elapses,
 *    below the M-of-N approval threshold, or while contested. A recovery
 *    row cannot be BORN completed. There is deliberately NO email column
 *    anywhere in the recovery schema — recovery is never an email reset.
 *
 *  - Offline vouchers (DOCUMENT 6.3 §2/§5): CHECK settled bounds — you
 *    cannot offline-settle more than you reserved (the per-voucher
 *    double-spend bound), and settled amounts never decrease. Deferred
 *    records are replay-bounded by a per-voucher unique nonce.
 *
 *  - Minimized disclosure (DOCUMENT 6.4 §2/§3): the schema stores ONLY
 *    the public statement, the commitment, and the proof — there is no
 *    witness column, and I8's forbidden-fragment scan covers this file.
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
        // identities — the multi-provider aggregation record (DOCUMENT 6.1
        // §3): versioned algorithm, provider attestation summaries (opaque
        // commitments only — never raw PII, I8), explainable + appealable.
        // ------------------------------------------------------------------
        Schema::create('identities', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('subject_commitment')->unique(); // opaque, non-PII
            $table->string('aggregation_version', 32);
            $table->unsignedSmallInteger('effective_rung');
            $table->jsonb('provider_attestations')->default('[]');
            $table->string('appeal_status', 16)->default('none');
            $table->decimal('sybil_risk_score', 5, 4)->default('0');
            $table->text('explanation');
            $table->timestampsTz();
        });

        DB::statement('ALTER TABLE identities ADD CONSTRAINT identities_rung_check CHECK (effective_rung BETWEEN 0 AND 3)');
        DB::statement("ALTER TABLE identities ADD CONSTRAINT identities_appeal_check CHECK (appeal_status IN ('none','open','upheld','denied'))");
        DB::statement('ALTER TABLE identities ADD CONSTRAINT identities_sybil_score_check CHECK (sybil_risk_score >= 0 AND sybil_risk_score <= 1)');
        DB::statement('ALTER TABLE identities ADD CONSTRAINT identities_explanation_check CHECK (length(trim(explanation)) > 0)');

        // ------------------------------------------------------------------
        // constitutional_parameters — amendable only by the constitutional
        // process. A value change without a fresh amendment ref is refused
        // at the DB layer (DOCUMENT 6.2 §2).
        // ------------------------------------------------------------------
        Schema::create('constitutional_parameters', function (Blueprint $table): void {
            $table->string('key', 64)->primary();
            $table->bigInteger('value_minor');
            $table->string('amendment_ref');
            $table->timestampsTz();
        });

        DB::statement('ALTER TABLE constitutional_parameters ADD CONSTRAINT constitutional_parameters_ref_check CHECK (length(trim(amendment_ref)) > 0)');

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION constitutional_parameters_guard_amendment()
            RETURNS trigger AS $$
            BEGIN
                IF NEW.value_minor IS DISTINCT FROM OLD.value_minor THEN
                    IF NEW.amendment_ref IS NULL
                        OR length(trim(NEW.amendment_ref)) = 0
                        OR NEW.amendment_ref = OLD.amendment_ref THEN
                        RAISE EXCEPTION 'CONSTITUTIONAL PARAMETER: the value may only change through the constitutional process, which must leave a new amendment reference';
                    END IF;
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER trg_constitutional_parameters_amendment
            BEFORE UPDATE ON constitutional_parameters
            FOR EACH ROW
            EXECUTE FUNCTION constitutional_parameters_guard_amendment();
        SQL);

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION constitutional_parameters_forbid_delete()
            RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'CONSTITUTIONAL PARAMETER: parameters are amended, never deleted';
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER trg_constitutional_parameters_no_delete
            BEFORE DELETE ON constitutional_parameters
            FOR EACH ROW
            EXECUTE FUNCTION constitutional_parameters_forbid_delete();
        SQL);

        // ------------------------------------------------------------------
        // rung1_pool_grants — bookkeeping of probationary-pool grants. The
        // HARD CAP: the lifetime sum of grants may never exceed the
        // constitutional parameter, so even a successful Sybil attack on
        // Rung-1 is bounded in damage (DOCUMENT 6.2 §0/§2).
        // ------------------------------------------------------------------
        Schema::create('rung1_pool_grants', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('identity_id')->constrained('identities');
            $table->bigInteger('amount_minor');
            $table->string('idempotency_key')->unique();
            $table->timestampTz('granted_at');
        });

        DB::statement('ALTER TABLE rung1_pool_grants ADD CONSTRAINT rung1_pool_grants_positive_check CHECK (amount_minor > 0)');

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION rung1_pool_guard_cap()
            RETURNS trigger AS $$
            DECLARE
                cap bigint;
                issued bigint;
            BEGIN
                -- Serialize concurrent grants: the cap is one shared budget.
                PERFORM pg_advisory_xact_lock(hashtext('rung1_pool_cap'));

                SELECT value_minor INTO cap
                FROM constitutional_parameters
                WHERE key = 'rung1_pool_cap_minor';

                IF cap IS NULL THEN
                    RAISE EXCEPTION 'RUNG-1 POOL: no constitutional cap is defined; the pool fails CLOSED';
                END IF;

                SELECT COALESCE(SUM(amount_minor), 0) INTO issued
                FROM rung1_pool_grants;

                IF issued + NEW.amount_minor > cap THEN
                    RAISE EXCEPTION 'RUNG-1 POOL: grant would exceed the hard constitutional cap (issued % + grant % > cap %)', issued, NEW.amount_minor, cap;
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER trg_rung1_pool_cap
            BEFORE INSERT ON rung1_pool_grants
            FOR EACH ROW
            EXECUTE FUNCTION rung1_pool_guard_cap();
        SQL);

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION rung1_pool_grants_forbid_mutation()
            RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'RUNG-1 POOL: grants are append-only bookkeeping against the cap; rewriting history would un-spend the budget';
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER trg_rung1_pool_grants_immutable
            BEFORE UPDATE OR DELETE ON rung1_pool_grants
            FOR EACH ROW
            EXECUTE FUNCTION rung1_pool_grants_forbid_mutation();
        SQL);

        // ------------------------------------------------------------------
        // attestation_vestings — ramp toward full value over time; RESET on
        // slash so a fresh Sybil identity cannot immediately extract full
        // value (DOCUMENT 6.2 §2).
        // ------------------------------------------------------------------
        Schema::create('attestation_vestings', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('identity_id')->constrained('identities');
            $table->timestampTz('vesting_started_at');
            $table->unsignedInteger('vesting_days');
            $table->unsignedInteger('slash_count')->default(0);
            $table->timestampTz('last_slashed_at')->nullable();
            $table->timestampsTz();
            $table->unique('identity_id');
        });

        DB::statement('ALTER TABLE attestation_vestings ADD CONSTRAINT attestation_vestings_days_check CHECK (vesting_days > 0)');

        // ------------------------------------------------------------------
        // sybil_bounties — the standing public bounty for discovering Sybil
        // clusters (DOCUMENT 6.2 §2). Targets CLUSTERS, never individuals.
        // ------------------------------------------------------------------
        Schema::create('sybil_bounties', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('reporter_commitment'); // opaque — reporters may be pseudonymous
            $table->jsonb('cluster_evidence');
            $table->string('status', 16)->default('open');
            $table->text('resolution_note')->nullable();
            $table->timestampsTz();
        });

        DB::statement("ALTER TABLE sybil_bounties ADD CONSTRAINT sybil_bounties_status_check CHECK (status IN ('open','awarded','rejected'))");

        // ------------------------------------------------------------------
        // Social recovery (DOCUMENT 6.2 §3): guardian sets + timelocked,
        // contestable recovery attempts. No email column exists anywhere
        // here, by design: recovery is never a low-friction email reset.
        // ------------------------------------------------------------------
        Schema::create('guardian_sets', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('identity_id')->constrained('identities');
            $table->jsonb('guardian_public_keys'); // Ed25519, base64
            $table->unsignedSmallInteger('threshold'); // M of N
            $table->timestampsTz();
            $table->unique('identity_id');
        });

        DB::statement('ALTER TABLE guardian_sets ADD CONSTRAINT guardian_sets_threshold_check CHECK (threshold >= 2)');

        Schema::create('recovery_attempts', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('guardian_set_id')->constrained('guardian_sets');
            $table->string('new_key_commitment');
            $table->string('status', 16)->default('initiated');
            $table->timestampTz('initiated_at');
            $table->timestampTz('challenge_window_ends_at');
            $table->jsonb('guardian_approvals')->default('[]'); // list of {guardian_key, signature}
            $table->timestampTz('contested_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->text('decision_receipt')->nullable();
            $table->boolean('elevated_monitoring')->default(false);
            $table->timestampsTz();
        });

        DB::statement("ALTER TABLE recovery_attempts ADD CONSTRAINT recovery_attempts_status_check CHECK (status IN ('initiated','contested','completed','abandoned'))");

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION recovery_guard_completion()
            RETURNS trigger AS $$
            DECLARE
                required smallint;
                approvals int;
            BEGIN
                -- A recovery cannot be BORN completed: it must pass through
                -- the timelocked window as a real, contestable interval.
                IF TG_OP = 'INSERT' THEN
                    IF NEW.status <> 'initiated' THEN
                        RAISE EXCEPTION 'SOCIAL RECOVERY: an attempt is born initiated; it cannot be born %', NEW.status;
                    END IF;
                    IF NEW.challenge_window_ends_at <= NEW.initiated_at THEN
                        RAISE EXCEPTION 'SOCIAL RECOVERY: the challenge window must be a real interval of time';
                    END IF;
                    RETURN NEW;
                END IF;

                IF NEW.status = 'completed' AND OLD.status <> 'completed' THEN
                    IF OLD.contested_at IS NOT NULL OR NEW.contested_at IS NOT NULL THEN
                        RAISE EXCEPTION 'SOCIAL RECOVERY: a contested recovery cannot complete; the contest must be resolved by the human process first';
                    END IF;

                    IF now() < OLD.challenge_window_ends_at THEN
                        RAISE EXCEPTION 'SOCIAL RECOVERY: the timelocked challenge window has not elapsed; a malicious recovery must remain contestable for the full window';
                    END IF;

                    SELECT threshold INTO required FROM guardian_sets WHERE id = OLD.guardian_set_id;
                    approvals := jsonb_array_length(NEW.guardian_approvals);

                    IF approvals < required THEN
                        RAISE EXCEPTION 'SOCIAL RECOVERY: % of % required guardian approvals present; sub-threshold recovery is refused', approvals, required;
                    END IF;

                    IF NEW.decision_receipt IS NULL OR length(trim(NEW.decision_receipt)) = 0 THEN
                        RAISE EXCEPTION 'SOCIAL RECOVERY: a completed recovery must carry a decision receipt';
                    END IF;

                    IF NEW.elevated_monitoring IS DISTINCT FROM true THEN
                        RAISE EXCEPTION 'SOCIAL RECOVERY: a completed recovery must place the account under elevated monitoring';
                    END IF;
                END IF;

                -- The window is part of the public record of the attempt;
                -- it cannot be quietly shortened after initiation.
                IF NEW.challenge_window_ends_at < OLD.challenge_window_ends_at THEN
                    RAISE EXCEPTION 'SOCIAL RECOVERY: the challenge window cannot be shortened after initiation';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER trg_recovery_guard_completion
            BEFORE INSERT OR UPDATE ON recovery_attempts
            FOR EACH ROW
            EXECUTE FUNCTION recovery_guard_completion();
        SQL);

        // ------------------------------------------------------------------
        // Offline vouchers (DOCUMENT 6.3): balance-reservation with an
        // explicit per-voucher double-spend bound = the reserved amount.
        // ------------------------------------------------------------------
        Schema::create('offline_vouchers', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('account_id')->constrained('accounts');
            $table->foreignUlid('currency_id')->constrained('currencies');
            $table->bigInteger('reserved_amount_minor');
            $table->bigInteger('settled_amount_minor')->default(0);
            $table->string('reservation_transaction_id');
            $table->string('holder_public_key'); // Ed25519, base64
            $table->string('status', 16)->default('reserved');
            $table->timestampTz('expires_at');
            $table->boolean('custodial_tier')->default(false);
            $table->boolean('custodial_disclosure_acknowledged')->default(false);
            $table->timestampsTz();
        });

        DB::statement('ALTER TABLE offline_vouchers ADD CONSTRAINT offline_vouchers_reserved_check CHECK (reserved_amount_minor > 0)');
        DB::statement('ALTER TABLE offline_vouchers ADD CONSTRAINT offline_vouchers_bound_check CHECK (settled_amount_minor >= 0 AND settled_amount_minor <= reserved_amount_minor)');
        DB::statement("ALTER TABLE offline_vouchers ADD CONSTRAINT offline_vouchers_status_check CHECK (status IN ('reserved','closed','expired'))");
        // Sidq: the custodial tier is an informed trade, never a default
        // slipped past the user.
        DB::statement('ALTER TABLE offline_vouchers ADD CONSTRAINT offline_vouchers_custodial_consent_check CHECK (custodial_tier = false OR custodial_disclosure_acknowledged = true)');

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION offline_vouchers_guard_settlement()
            RETURNS trigger AS $$
            BEGIN
                IF NEW.settled_amount_minor < OLD.settled_amount_minor THEN
                    RAISE EXCEPTION 'OFFLINE VOUCHER: settled amounts never decrease; un-settling would reopen the double-spend bound';
                END IF;
                IF NEW.reserved_amount_minor IS DISTINCT FROM OLD.reserved_amount_minor THEN
                    RAISE EXCEPTION 'OFFLINE VOUCHER: the reservation (the double-spend bound) is immutable for the life of the voucher';
                END IF;
                IF OLD.status <> 'reserved' AND NEW.settled_amount_minor <> OLD.settled_amount_minor THEN
                    RAISE EXCEPTION 'OFFLINE VOUCHER: a % voucher accepts no further settlement', OLD.status;
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER trg_offline_vouchers_settlement
            BEFORE UPDATE ON offline_vouchers
            FOR EACH ROW
            EXECUTE FUNCTION offline_vouchers_guard_settlement();
        SQL);

        Schema::create('deferred_settlements', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('voucher_id')->constrained('offline_vouchers');
            $table->foreignUlid('payee_account_id')->constrained('accounts');
            $table->bigInteger('amount_minor');
            $table->string('nonce');
            $table->string('holder_signature'); // Ed25519 detached, base64
            $table->string('status', 16)->default('settled');
            $table->string('settlement_transaction_id')->nullable();
            $table->timestampsTz();
            $table->unique(['voucher_id', 'nonce']); // replay bound
        });

        DB::statement('ALTER TABLE deferred_settlements ADD CONSTRAINT deferred_settlements_positive_check CHECK (amount_minor > 0)');
        DB::statement("ALTER TABLE deferred_settlements ADD CONSTRAINT deferred_settlements_status_check CHECK (status IN ('settled','rejected'))");

        // ------------------------------------------------------------------
        // Minimized disclosure (DOCUMENT 6.4): the protocol stores the
        // STATEMENT, the COMMITMENT, and the PROOF — never the witness.
        // There is deliberately no witness column in this schema.
        // ------------------------------------------------------------------
        Schema::create('disclosure_proofs', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('statement'); // the public criterion, e.g. 'over_18'
            $table->string('subject_commitment');
            $table->text('proof_blob'); // opaque proof bytes, base64
            $table->string('proof_system', 64);
            $table->boolean('verified')->default(false);
            $table->boolean('consent_revoked')->default(false);
            $table->timestampTz('expires_at')->nullable();
            $table->timestampsTz();
        });

        DB::statement('ALTER TABLE disclosure_proofs ADD CONSTRAINT disclosure_proofs_statement_check CHECK (length(trim(statement)) > 0)');

        // ------------------------------------------------------------------
        // Grants: identity tables belong to the identity layer; aevum_app
        // may READ identity outcomes (rungs gate experiences) but never
        // write them.
        // ------------------------------------------------------------------
        DB::unprepared(<<<'SQL'
            GRANT SELECT, INSERT, UPDATE ON identities, attestation_vestings, sybil_bounties, guardian_sets, recovery_attempts, disclosure_proofs TO meridian_app;
            GRANT SELECT, INSERT ON rung1_pool_grants TO meridian_app;
            GRANT SELECT ON constitutional_parameters TO meridian_app;
            GRANT SELECT, INSERT, UPDATE ON offline_vouchers, deferred_settlements TO meridian_app;
            GRANT SELECT ON identities, constitutional_parameters TO aevum_app;
            REVOKE INSERT, UPDATE, DELETE, TRUNCATE ON identities, rung1_pool_grants, constitutional_parameters, offline_vouchers, deferred_settlements FROM aevum_app;
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('disclosure_proofs');
        Schema::dropIfExists('deferred_settlements');
        Schema::dropIfExists('offline_vouchers');
        Schema::dropIfExists('recovery_attempts');
        Schema::dropIfExists('guardian_sets');
        Schema::dropIfExists('sybil_bounties');
        Schema::dropIfExists('attestation_vestings');
        Schema::dropIfExists('rung1_pool_grants');
        Schema::dropIfExists('constitutional_parameters');
        Schema::dropIfExists('identities');
        DB::unprepared('DROP FUNCTION IF EXISTS constitutional_parameters_guard_amendment CASCADE');
        DB::unprepared('DROP FUNCTION IF EXISTS constitutional_parameters_forbid_delete CASCADE');
        DB::unprepared('DROP FUNCTION IF EXISTS rung1_pool_guard_cap CASCADE');
        DB::unprepared('DROP FUNCTION IF EXISTS rung1_pool_grants_forbid_mutation CASCADE');
        DB::unprepared('DROP FUNCTION IF EXISTS recovery_guard_completion CASCADE');
        DB::unprepared('DROP FUNCTION IF EXISTS offline_vouchers_guard_settlement CASCADE');
    }
};
