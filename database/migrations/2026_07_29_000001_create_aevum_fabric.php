<?php

/**
 * SPDX-License-Identifier: LicenseRef-AVL-2.0
 *
 * DEV-4.4 — AEVUM CURRENCY FABRIC, TIER-0, AND MEMBRANE (experience face).
 *
 * Aevum's data model per DOCUMENT 4.4: experience_specs, asset_labels,
 * global_blocks, user_client_preferences. The load-bearing constraint,
 * enforced here at the DATABASE layer:
 *
 *  - A-§C.14: the aevum_app role has NO write privilege of any kind on
 *    the ledger's entries/transactions tables. Aevum proposes, surfaces,
 *    filters, or refuses — it never authors a Meridian balance change.
 *
 *  - A-§C.10: a global_blocks row cannot reach status = 'active' without
 *    timelock_until elapsed, a non-empty public justification, and a
 *    closed appeal window (trigger global_blocks_guard_activation).
 *    A row cannot be BORN active: unilateral/silent global blocking is
 *    structurally rejected, not merely discouraged.
 *
 * Tier-0 rules hold no mutable state of their own — they are pure
 * functions over inputs, which is why no table exists for them.
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
        // Roles: aevum_app is the system-of-engagement's DB identity.
        // ------------------------------------------------------------------
        DB::unprepared(<<<'SQL'
DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'aevum_app') THEN
        CREATE ROLE aevum_app NOLOGIN;
    END IF;
END
$$;
SQL);

        // ------------------------------------------------------------------
        // experience_specs — pluggable currency experiences (DOCUMENT 4.4).
        // core_riba_checked records that the registry ran the A-§C.9 gate.
        // ------------------------------------------------------------------
        Schema::create('experience_specs', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('currency_id');
            $table->jsonb('presentation');
            $table->boolean('core_riba_checked')->default(false);
            $table->timestampTz('registered_at');

            $table->unique('currency_id');
            $table->foreign('currency_id')->references('id')->on('currencies');
        });

        // ------------------------------------------------------------------
        // asset_labels — multi-sourced, contestable labels with provenance
        // (DOCUMENT 0.5: labels, not verdicts; users decide).
        // ------------------------------------------------------------------
        Schema::create('asset_labels', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('asset_ref');
            $table->string('category');
            $table->string('source');
            $table->text('provenance');
            $table->string('contestation_status')->default('uncontested');
            $table->timestampTz('labeled_at');

            $table->index('asset_ref');
        });

        DB::unprepared(<<<'SQL'
ALTER TABLE asset_labels
    ADD CONSTRAINT asset_labels_category_check CHECK (
        category IN ('weapons', 'gambling', 'usury', 'adult', 'surveillance',
                     'environmental_harm', 'sanctioned', 'other')
    ),
    ADD CONSTRAINT asset_labels_contestation_check CHECK (
        contestation_status IN ('uncontested', 'contested', 'upheld', 'withdrawn')
    ),
    ADD CONSTRAINT asset_labels_provenance_nonempty CHECK (length(provenance) > 0);
SQL);

        // ------------------------------------------------------------------
        // global_blocks — the ONE membrane power that overrides individual
        // user sovereignty, so it is the one that is constitutionally
        // gated (A-§C.10): timelock + public justification + appeal path.
        // ------------------------------------------------------------------
        Schema::create('global_blocks', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('asset_ref');
            $table->text('justification');
            $table->timestampTz('proposed_at');
            $table->timestampTz('timelock_until');
            $table->string('appeal_status')->default('none');
            $table->string('status')->default('proposed');
            $table->string('transparency_log_ref');

            $table->index(['asset_ref', 'status']);
        });

        DB::unprepared(<<<'SQL'
ALTER TABLE global_blocks
    ADD CONSTRAINT global_blocks_status_check CHECK (
        status IN ('proposed', 'active', 'appealed', 'void')
    ),
    ADD CONSTRAINT global_blocks_appeal_check CHECK (
        appeal_status IN ('none', 'open', 'dismissed', 'upheld')
    ),
    ADD CONSTRAINT global_blocks_justification_nonempty CHECK (length(justification) > 0),
    ADD CONSTRAINT global_blocks_timelock_after_proposal CHECK (timelock_until > proposed_at),
    ADD CONSTRAINT global_blocks_upheld_appeal_voids CHECK (
        appeal_status <> 'upheld' OR status = 'void'
    );
SQL);

        // The constitutional activation guard. A-§C.10, DOCUMENT 0.5:
        // "no global block activates without timelock + justification +
        // appeal path; a unilateral/silent global block is rejected."
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION global_blocks_guard_activation()
RETURNS trigger
LANGUAGE plpgsql
AS $$
BEGIN
    IF TG_OP = 'INSERT' THEN
        IF NEW.status <> 'proposed' THEN
            RAISE EXCEPTION 'A-C.10: a global block can only ENTER the constitutional process (status=proposed); it can never be born %', NEW.status
                USING ERRCODE = 'check_violation';
        END IF;
        RETURN NEW;
    END IF;

    -- UPDATE path: gate every transition INTO 'active'.
    IF NEW.status = 'active' AND OLD.status <> 'active' THEN
        IF OLD.status <> 'proposed' THEN
            RAISE EXCEPTION 'A-C.10: only a proposed global block may activate; % may not', OLD.status
                USING ERRCODE = 'check_violation';
        END IF;
        IF NEW.timelock_until > now() THEN
            RAISE EXCEPTION 'A-C.10: global block timelock has not elapsed (until %)', NEW.timelock_until
                USING ERRCODE = 'check_violation';
        END IF;
        IF NEW.justification IS NULL OR length(NEW.justification) = 0 THEN
            RAISE EXCEPTION 'A-C.10: a global block requires a public written justification'
                USING ERRCODE = 'check_violation';
        END IF;
        IF NEW.appeal_status = 'open' THEN
            RAISE EXCEPTION 'A-C.10: a global block cannot activate while an appeal is open'
                USING ERRCODE = 'check_violation';
        END IF;
        IF NEW.appeal_status = 'upheld' THEN
            RAISE EXCEPTION 'A-C.10: an upheld appeal voids the block; it may never activate'
                USING ERRCODE = 'check_violation';
        END IF;
    END IF;

    -- The timelock and justification are part of the public record: they
    -- may not be quietly rewritten after proposal.
    IF TG_OP = 'UPDATE' AND (
        NEW.timelock_until <> OLD.timelock_until
        OR NEW.justification <> OLD.justification
        OR NEW.proposed_at <> OLD.proposed_at
    ) THEN
        RAISE EXCEPTION 'A-C.10: the proposed timelock, justification, and proposal time are immutable public record'
            USING ERRCODE = 'check_violation';
    END IF;

    RETURN NEW;
END;
$$;

CREATE TRIGGER trg_global_blocks_guard_activation
    BEFORE INSERT OR UPDATE ON global_blocks
    FOR EACH ROW
    EXECUTE FUNCTION global_blocks_guard_activation();
SQL);

        // ------------------------------------------------------------------
        // user_client_preferences — user-sovereign filter rules
        // (DOCUMENT 0.5 Face 1: each wallet sets its own rules).
        // ------------------------------------------------------------------
        Schema::create('user_client_preferences', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('user_ref');
            $table->jsonb('filter_rules');
            $table->timestampTz('updated_at');

            $table->unique('user_ref');
        });

        // ------------------------------------------------------------------
        // A-§C.14 grants: Aevum writes ONLY its own four tables. It may
        // read the currencies/issuance_policies catalog (needed for the
        // A-§C.9 Core-Riba gate) but holds NO write privilege — and
        // categorically NO privilege of any kind that could author a
        // ledger row.
        // ------------------------------------------------------------------
        DB::unprepared(<<<'SQL'
GRANT SELECT, INSERT, UPDATE ON experience_specs, asset_labels, global_blocks, user_client_preferences TO aevum_app;
GRANT SELECT, INSERT, UPDATE ON experience_specs, asset_labels, global_blocks, user_client_preferences TO meridian_app;
GRANT SELECT ON asset_labels, global_blocks TO meridian_membrane;
GRANT SELECT ON currencies, issuance_policies TO aevum_app;

REVOKE INSERT, UPDATE, DELETE, TRUNCATE ON entries, transactions FROM aevum_app;
REVOKE INSERT, UPDATE, DELETE, TRUNCATE ON accounts FROM aevum_app;
SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS trg_global_blocks_guard_activation ON global_blocks');
        DB::unprepared('DROP FUNCTION IF EXISTS global_blocks_guard_activation()');
        Schema::dropIfExists('user_client_preferences');
        Schema::dropIfExists('global_blocks');
        Schema::dropIfExists('asset_labels');
        Schema::dropIfExists('experience_specs');
    }
};
