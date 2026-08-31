<?php

/**
 * SPDX-License-Identifier: LicenseRef-MVL-2.0 AND LicenseRef-AVL-2.0
 *
 * DEV-8 / DOCUMENT 8.1 — Crisis Runbook & Incident-Commander Charter.
 * DEV-8 / DOCUMENT 8.2 — The Protocol-Loss Fund Charter.
 *
 * Crisis side: incidents carry severity S1–S4 and BOUND disclosure
 * timelines computed at declaration from commitments published in
 * advance — so disclosure is never at the discretion of those who would
 * prefer silence. DB triggers make the timelines one-way: a deadline can
 * never be pushed later, a severity can never be quietly rewritten, and
 * disclosures are append-only. The incident commander is a ROLE, not a
 * person, and holds NO ledger power of any kind — "emergency" is never
 * a license to violate the spine.
 *
 * Fund side: the Protocol-Loss Fund covers protocol bugs ONLY —
 * explicitly not market risk, not user error, not disclosed experimental
 * risk. The boundary is structural: a DB trigger makes approving a claim
 * in any category but 'protocol_bug' impossible, decisions require a
 * public receipt, and a payout can attach only to an approved claim.
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
        // DOCUMENT 8.1 — incidents with bound disclosure timelines.
        // ------------------------------------------------------------------
        Schema::create('incidents', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->string('severity', 4);
            $table->string('summary', 1024);
            $table->string('commander_role', 64);
            $table->string('status', 16)->default('open');
            $table->string('trigger_source', 128)->default('manual');
            $table->timestampTz('declared_at')->useCurrent();
            $table->timestampTz('status_page_due_at');
            $table->timestampTz('preliminary_report_due_at');
            $table->timestampTz('postmortem_due_at');
            $table->timestampTz('closed_at')->nullable();
        });

        DB::unprepared(<<<'SQL'
ALTER TABLE incidents
    ADD CONSTRAINT incidents_severity_check CHECK (severity IN ('s1', 's2', 's3', 's4')),
    ADD CONSTRAINT incidents_status_check CHECK (status IN ('open', 'closed')),
    ADD CONSTRAINT incidents_summary_nonempty CHECK (length(summary) > 0),
    ADD CONSTRAINT incidents_commander_nonempty CHECK (length(commander_role) > 0),
    -- The clock is ordered: status page first, then the preliminary
    -- report, then the full post-mortem.
    ADD CONSTRAINT incidents_timeline_ordered CHECK (
        status_page_due_at <= preliminary_report_due_at
        AND preliminary_report_due_at <= postmortem_due_at
    ),
    ADD CONSTRAINT incidents_closed_shape CHECK (
        (status = 'closed') = (closed_at IS NOT NULL)
    );

-- The disclosure clock is one-way: no deadline may ever move later, and
-- the severity that set the clock may never be rewritten. The commitment
-- was published before the failure; it cannot be renegotiated during one.
CREATE OR REPLACE FUNCTION incidents_guard_timeline()
RETURNS trigger AS $$
BEGIN
    IF NEW.severity IS DISTINCT FROM OLD.severity THEN
        RAISE EXCEPTION
            'CRISIS: the severity that bound the disclosure clock cannot be rewritten mid-incident.';
    END IF;

    IF NEW.status_page_due_at > OLD.status_page_due_at
        OR NEW.preliminary_report_due_at > OLD.preliminary_report_due_at
        OR NEW.postmortem_due_at > OLD.postmortem_due_at THEN
        RAISE EXCEPTION
            'CRISIS: a disclosure deadline can never be pushed later. The clock was set before the failure and is not renegotiable during one.';
    END IF;

    IF NEW.declared_at IS DISTINCT FROM OLD.declared_at THEN
        RAISE EXCEPTION 'CRISIS: the declaration moment is a historical fact.';
    END IF;

    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER trg_incidents_guard_timeline
    BEFORE UPDATE ON incidents
    FOR EACH ROW EXECUTE FUNCTION incidents_guard_timeline();

CREATE OR REPLACE FUNCTION incidents_forbid_delete()
RETURNS trigger AS $$
BEGIN
    RAISE EXCEPTION 'CRISIS: an incident record is never deleted. The body fails openly or not at all.';
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER trg_incidents_forbid_delete
    BEFORE DELETE ON incidents
    FOR EACH ROW EXECUTE FUNCTION incidents_forbid_delete();
SQL);

        // ------------------------------------------------------------------
        // DOCUMENT 8.1 §2 — the disclosures themselves, append-only.
        // ------------------------------------------------------------------
        Schema::create('incident_disclosures', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->char('incident_id', 26);
            $table->string('kind', 24);
            $table->text('content');
            $table->timestampTz('published_at')->useCurrent();

            $table->foreign('incident_id')->references('id')->on('incidents');
            $table->unique(['incident_id', 'kind']);
        });

        DB::unprepared(<<<'SQL'
ALTER TABLE incident_disclosures
    ADD CONSTRAINT incident_disclosures_kind_check
        CHECK (kind IN ('status_page', 'preliminary_report', 'postmortem')),
    ADD CONSTRAINT incident_disclosures_content_nonempty CHECK (length(content) > 0);

CREATE OR REPLACE FUNCTION incident_disclosures_forbid_mutation()
RETURNS trigger AS $$
BEGIN
    RAISE EXCEPTION
        'CRISIS: a published disclosure is append-only. The truth once told is not untold.';
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER trg_incident_disclosures_immutable
    BEFORE UPDATE OR DELETE ON incident_disclosures
    FOR EACH ROW EXECUTE FUNCTION incident_disclosures_forbid_mutation();
SQL);

        // ------------------------------------------------------------------
        // DOCUMENT 8.2 — the Protocol-Loss Fund's claims, with the
        // boundary made structural.
        // ------------------------------------------------------------------
        Schema::create('loss_fund_claims', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->char('claimant_account_id', 26);
            $table->bigInteger('amount_minor');
            $table->text('narrative');
            $table->text('exclusions_disclosed');
            $table->string('category', 32)->nullable();
            $table->string('status', 16)->default('submitted');
            $table->text('decision_receipt')->nullable();
            $table->char('payout_transaction_id', 26)->nullable();
            $table->timestampTz('appealed_at')->nullable();
            $table->text('appeal_note')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('decided_at')->nullable();

            $table->foreign('claimant_account_id')->references('id')->on('accounts');
            $table->foreign('payout_transaction_id')->references('id')->on('transactions');
        });

        DB::unprepared(<<<'SQL'
ALTER TABLE loss_fund_claims
    ADD CONSTRAINT loss_fund_claims_amount_positive CHECK (amount_minor > 0),
    ADD CONSTRAINT loss_fund_claims_narrative_nonempty CHECK (length(narrative) > 0),
    -- The exclusions were stated to the claimant UP FRONT: a claim row
    -- without the disclosure text cannot exist.
    ADD CONSTRAINT loss_fund_claims_exclusions_stated CHECK (length(exclusions_disclosed) > 0),
    ADD CONSTRAINT loss_fund_claims_status_check
        CHECK (status IN ('submitted', 'approved', 'denied', 'appealed')),
    ADD CONSTRAINT loss_fund_claims_category_check
        CHECK (category IS NULL OR category IN
            ('protocol_bug', 'market_risk', 'user_error', 'disclosed_experimental')),
    -- THE BOUNDARY (§2, §4): approval is structurally possible ONLY for a
    -- protocol bug. Market risk, user error, and disclosed experimental
    -- risk cannot be approved by anyone, under any pressure.
    ADD CONSTRAINT loss_fund_claims_boundary
        CHECK (status <> 'approved' OR category = 'protocol_bug'),
    -- Every decision carries a public receipt (an appeal presupposes a
    -- decision, so it too must carry one), and an undecided claim must
    -- carry none.
    ADD CONSTRAINT loss_fund_claims_decision_shape CHECK (
        status = 'submitted'
            OR (decision_receipt IS NOT NULL AND decided_at IS NOT NULL)
    ),
    ADD CONSTRAINT loss_fund_claims_submitted_undecided CHECK (
        status <> 'submitted' OR decision_receipt IS NULL
    ),
    -- An appeal is always recorded with its moment (one-way: a resolved
    -- appeal keeps its history).
    ADD CONSTRAINT loss_fund_claims_appeal_shape CHECK (
        status <> 'appealed' OR appealed_at IS NOT NULL
    ),
    -- A payout can attach only to an approved claim.
    ADD CONSTRAINT loss_fund_claims_payout_requires_approval
        CHECK (payout_transaction_id IS NULL OR status = 'approved');

-- Once decided and paid, the record is history: the payout reference and
-- the decision that justified it can never be rewritten.
CREATE OR REPLACE FUNCTION loss_fund_claims_guard_decision()
RETURNS trigger AS $$
BEGIN
    IF OLD.payout_transaction_id IS NOT NULL THEN
        IF NEW.payout_transaction_id IS DISTINCT FROM OLD.payout_transaction_id
            OR NEW.status IS DISTINCT FROM OLD.status
            OR NEW.category IS DISTINCT FROM OLD.category
            OR NEW.decision_receipt IS DISTINCT FROM OLD.decision_receipt THEN
            RAISE EXCEPTION
                'LOSS FUND: a paid claim is a closed historical fact and cannot be rewritten.';
        END IF;
    END IF;

    IF NEW.amount_minor IS DISTINCT FROM OLD.amount_minor
        OR NEW.claimant_account_id IS DISTINCT FROM OLD.claimant_account_id
        OR NEW.exclusions_disclosed IS DISTINCT FROM OLD.exclusions_disclosed THEN
        RAISE EXCEPTION
            'LOSS FUND: the claim as submitted (amount, claimant, disclosed exclusions) is immutable.';
    END IF;

    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER trg_loss_fund_claims_guard_decision
    BEFORE UPDATE ON loss_fund_claims
    FOR EACH ROW EXECUTE FUNCTION loss_fund_claims_guard_decision();

CREATE OR REPLACE FUNCTION loss_fund_claims_forbid_delete()
RETURNS trigger AS $$
BEGIN
    RAISE EXCEPTION
        'LOSS FUND: claims and their decisions are public record and are never deleted.';
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER trg_loss_fund_claims_forbid_delete
    BEFORE DELETE ON loss_fund_claims
    FOR EACH ROW EXECUTE FUNCTION loss_fund_claims_forbid_delete();
SQL);

        // ------------------------------------------------------------------
        // Grants. Transparency is the point: aevum_app may READ incidents,
        // disclosures, and claims (public record), never write them. The
        // policy engine may read incidents (it fires alongside them) but
        // holds no write privilege here either.
        // ------------------------------------------------------------------
        DB::unprepared(<<<'SQL'
GRANT SELECT, INSERT, UPDATE ON incidents TO meridian_app;
GRANT SELECT, INSERT ON incident_disclosures TO meridian_app;
GRANT SELECT, INSERT, UPDATE ON loss_fund_claims TO meridian_app;
GRANT SELECT ON incidents, incident_disclosures, loss_fund_claims TO aevum_app;
GRANT SELECT ON incidents TO meridian_policy_engine;
REVOKE INSERT, UPDATE, DELETE, TRUNCATE ON incidents, incident_disclosures, loss_fund_claims FROM aevum_app;
REVOKE INSERT, UPDATE, DELETE, TRUNCATE ON incidents FROM meridian_policy_engine;
SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
DROP TRIGGER IF EXISTS trg_loss_fund_claims_forbid_delete ON loss_fund_claims;
DROP TRIGGER IF EXISTS trg_loss_fund_claims_guard_decision ON loss_fund_claims;
DROP TRIGGER IF EXISTS trg_incident_disclosures_immutable ON incident_disclosures;
DROP TRIGGER IF EXISTS trg_incidents_forbid_delete ON incidents;
DROP TRIGGER IF EXISTS trg_incidents_guard_timeline ON incidents;
DROP FUNCTION IF EXISTS loss_fund_claims_forbid_delete();
DROP FUNCTION IF EXISTS loss_fund_claims_guard_decision();
DROP FUNCTION IF EXISTS incident_disclosures_forbid_mutation();
DROP FUNCTION IF EXISTS incidents_forbid_delete();
DROP FUNCTION IF EXISTS incidents_guard_timeline();
SQL);

        Schema::dropIfExists('loss_fund_claims');
        Schema::dropIfExists('incident_disclosures');
        Schema::dropIfExists('incidents');
    }
};
