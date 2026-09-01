<?php

/**
 * SPDX-License-Identifier: LicenseRef-MVL-2.0
 *
 * DEV-4.3 — DISPUTE / CLAWBACK / ARBITRATION ENGINE schema, and the
 * installation of the FULL I6-revised predicate at the database layer.
 *
 * This migration replaces the pre-DEV-4.3 form of
 * ledger_guard_personal_debit() (which rejected every arbitration
 * reversal outright, because the path did not yet exist) with the
 * complete auditable predicate of DOCUMENT 0.1 I6:
 *
 *   valid(d) ⟺ ∃ r : r.type = arbitration_reversal
 *              ∧ r.target_mint = (a specific txn_id)
 *              ∧ r.case = (a closed arbitration case_id)
 *              ∧ |d.amount| ≤ amount(r.target_mint)
 *              ∧ balanceAfter(d) ≥ undisputedCredits(account)
 *
 * A debit referencing NO specific target mint and NO closed case is a
 * punitive debit and is ALWAYS rejected. There is no third path.
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
        // dispute_profiles — per-currency dispute configuration.
        // ------------------------------------------------------------------
        Schema::create('dispute_profiles', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->char('currency_id', 26)->unique();
            $table->integer('window_seconds');
            $table->jsonb('bond_schedule')->default('[]');
            $table->string('settlement_mode', 16);
            $table->timestampTz('created_at')->useCurrent();

            $table->foreign('currency_id')->references('id')->on('currencies');
        });

        DB::unprepared(<<<'SQL'
ALTER TABLE dispute_profiles
    ADD CONSTRAINT dispute_profiles_mode_check
        CHECK (settlement_mode IN ('immediate', 'provisional')),
    ADD CONSTRAINT dispute_profiles_window_positive CHECK (window_seconds > 0);
SQL);

        // ------------------------------------------------------------------
        // attestation_disputes — a dispute is against a SPECIFIC
        // provisional mint, never against a person.
        // ------------------------------------------------------------------
        Schema::create('attestation_disputes', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->char('attestation_id', 26);
            $table->char('mint_transaction_id', 26);
            $table->integer('round')->default(1);
            $table->bigInteger('bond');
            $table->string('challenger_id', 64);
            $table->string('status', 24)->default('open');
            $table->jsonb('resolution')->nullable();
            $table->timestampTz('case_closed_at')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->foreign('attestation_id')->references('id')->on('attestations');
            $table->foreign('mint_transaction_id')->references('id')->on('transactions');
        });

        DB::unprepared(<<<'SQL'
ALTER TABLE attestation_disputes
    ADD CONSTRAINT attestation_disputes_status_check
        CHECK (status IN ('open', 'escalated', 'arbitrating', 'resolved_fraud', 'resolved_valid')),
    ADD CONSTRAINT attestation_disputes_bond_positive CHECK (bond > 0),
    ADD CONSTRAINT attestation_disputes_round_positive CHECK (round >= 1),
    -- A resolved case must be closed; an unresolved case must not be.
    ADD CONSTRAINT attestation_disputes_closure_shape CHECK (
        (status IN ('resolved_fraud', 'resolved_valid')) = (case_closed_at IS NOT NULL)
    );
SQL);

        // ------------------------------------------------------------------
        // clawbacks — the CHECK is constitutional: a clawback target is an
        // attester bond, an issuer bond, or the specific fraudulent mint.
        // NEVER a generic personal-account target. (DOCUMENT 4.3)
        // ------------------------------------------------------------------
        Schema::create('clawbacks', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->char('dispute_id', 26);
            $table->string('target', 32);
            $table->bigInteger('amount');
            $table->char('applied_transaction_id', 26)->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->foreign('dispute_id')->references('id')->on('attestation_disputes');
            $table->foreign('applied_transaction_id')->references('id')->on('transactions');
        });

        DB::unprepared(<<<'SQL'
ALTER TABLE clawbacks
    ADD CONSTRAINT clawbacks_target_check
        CHECK (target IN ('attester_bond', 'issuer_bond', 'specific_fraudulent_mint')),
    ADD CONSTRAINT clawbacks_amount_positive CHECK (amount > 0);
SQL);

        // ------------------------------------------------------------------
        // THE I6-REVISED PREDICATE — full form, replacing the pre-DEV-4.3
        // guard. This trigger runs AFTER trg_entries_balance_after
        // (alphabetical BEFORE-trigger order), so NEW.balance_after is set.
        // ------------------------------------------------------------------
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION ledger_guard_personal_debit()
RETURNS trigger
LANGUAGE plpgsql
AS $$
DECLARE
    acct_owner_type text;
    txn_kind text;
    txn_reverses_mint char(26);
    txn_case char(26);
    target_mint_kind text;
    target_mint_credit bigint;
    case_status text;
    case_closed timestamptz;
    case_mint char(26);
    undisputed_credits bigint;
BEGIN
    IF NEW.amount >= 0 THEN
        RETURN NEW;
    END IF;

    SELECT owner_type INTO acct_owner_type FROM accounts WHERE id = NEW.account_id;

    IF acct_owner_type <> 'person' THEN
        RETURN NEW;
    END IF;

    SELECT kind, reverses_mint_transaction_id, arbitration_case_id
        INTO txn_kind, txn_reverses_mint, txn_case
        FROM transactions WHERE id = NEW.transaction_id;

    IF txn_kind <> 'arbitration_reversal' THEN
        -- The holder-authorized path (I10). A debit referencing neither
        -- a holder authorization nor a closed arbitration case + specific
        -- fraudulent mint is a punitive debit and is always rejected.
        IF NEW.holder_authorization_ref IS NULL THEN
            RAISE EXCEPTION 'I6/I10: a debit against a personal account requires holder authorization; a debit referencing neither a holder authorization nor a closed arbitration case + specific fraudulent mint is a punitive debit and is always rejected';
        END IF;
        RETURN NEW;
    END IF;

    -- ================= THE I6-REVISED PREDICATE =================
    -- (1) The reversal references a specific minting transaction_id.
    IF txn_reverses_mint IS NULL THEN
        RAISE EXCEPTION 'I6: an arbitration reversal must reference a specific fraudulent mint transaction_id; a debit pointing only at a person is a punitive debit and is always rejected';
    END IF;

    SELECT kind INTO target_mint_kind FROM transactions WHERE id = txn_reverses_mint;
    IF target_mint_kind IS NULL OR target_mint_kind <> 'mint' THEN
        RAISE EXCEPTION 'I6: reverses_mint_transaction_id % does not reference an existing mint transaction', txn_reverses_mint;
    END IF;

    -- (2) The reversal references a CLOSED arbitration case that ruled
    --     fraud, and that case is bound to the SAME target mint.
    IF txn_case IS NULL THEN
        RAISE EXCEPTION 'I6: an arbitration reversal must reference a closed arbitration case_id';
    END IF;

    SELECT status, case_closed_at, mint_transaction_id
        INTO case_status, case_closed, case_mint
        FROM attestation_disputes WHERE id = txn_case;

    IF case_status IS NULL THEN
        RAISE EXCEPTION 'I6: arbitration_case_id % does not reference an existing arbitration case', txn_case;
    END IF;

    IF case_status <> 'resolved_fraud' OR case_closed IS NULL THEN
        RAISE EXCEPTION 'I6: arbitration case % is not a closed fraud ruling (status %); the reversal path requires a closed case', txn_case, case_status;
    END IF;

    IF case_mint IS DISTINCT FROM txn_reverses_mint THEN
        RAISE EXCEPTION 'I6: arbitration case % rules on mint %, not on the referenced mint %; a case may only unwind its own mint', txn_case, case_mint, txn_reverses_mint;
    END IF;

    -- (3) The amount is bounded by the target mint's credit to THIS
    --     account: |amount| <= amount(target_mint).
    SELECT amount INTO target_mint_credit
        FROM entries
        WHERE transaction_id = txn_reverses_mint
          AND account_id = NEW.account_id
          AND amount > 0
        LIMIT 1;

    IF target_mint_credit IS NULL THEN
        RAISE EXCEPTION 'I6: account % never received a credit from mint %; an innocent holder''s balance is never the source of clawback', NEW.account_id, txn_reverses_mint;
    END IF;

    IF ABS(NEW.amount) > target_mint_credit THEN
        RAISE EXCEPTION 'I6: reversal amount % exceeds the target mint''s credit % — the bound |amount| <= amount(target_mint) is violated', ABS(NEW.amount), target_mint_credit;
    END IF;

    -- (4) The resulting balance never drops below the holder's
    --     undisputed credits: balance_after >= undisputed_credits.
    --     Undisputed credits = all credits received minus credits from
    --     mints under any dispute not resolved in the holder's favor.
    SELECT
        COALESCE(SUM(e.amount) FILTER (WHERE e.amount > 0), 0)
        - COALESCE(SUM(e.amount) FILTER (
            WHERE e.amount > 0 AND EXISTS (
                SELECT 1 FROM attestation_disputes ad
                WHERE ad.mint_transaction_id = e.transaction_id
                  AND ad.status <> 'resolved_valid'
            )
        ), 0)
        INTO undisputed_credits
        FROM entries e
        WHERE e.account_id = NEW.account_id;

    IF NEW.balance_after < undisputed_credits THEN
        RAISE EXCEPTION 'I6: reversal would leave balance % below the holder''s undisputed credits % — rejected; the undisputed-credits floor is absolute', NEW.balance_after, undisputed_credits;
    END IF;

    RETURN NEW;
END;
$$;
SQL);

        // ------------------------------------------------------------------
        // Role grants.
        // ------------------------------------------------------------------
        DB::unprepared(<<<'SQL'
GRANT SELECT, INSERT, UPDATE ON dispute_profiles, attestation_disputes, clawbacks TO meridian_app;
GRANT SELECT ON dispute_profiles, attestation_disputes, clawbacks TO meridian_policy_engine;
GRANT SELECT ON dispute_profiles, attestation_disputes, clawbacks TO meridian_membrane;
SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('clawbacks');
        Schema::dropIfExists('attestation_disputes');
        Schema::dropIfExists('dispute_profiles');
        // Reinstating the pre-DEV-4.3 guard is intentionally NOT automated:
        // rolling back the dispute engine must be a deliberate operation.
    }
};
