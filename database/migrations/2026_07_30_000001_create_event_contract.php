<?php

/**
 * SPDX-License-Identifier: LicenseRef-MVL-2.0 AND LicenseRef-AVL-2.0
 *
 * DEV-7.1 — THE CROSS-SYSTEM EVENT CONTRACT data model (DOCUMENT 7.1).
 *
 * The signed, hash-chained, idempotent message stream between the two
 * legs. DATABASE-layer enforcement:
 *
 *  - Hash chain: a trigger recomputes entry_hash from the event's own
 *    content + prev_hash (pgcrypto sha256) and verifies prev_hash is
 *    the entry_hash of the latest chained event — a forged or spliced
 *    link is rejected at INSERT, independent of any service.
 *
 *  - Idempotency: idempotency_key is UNIQUE — a replayed INSERT is a
 *    constraint violation the service maps to a no-op.
 *
 *  - Immutability: events are append-only; only the processing-outcome
 *    fields (status, result_transaction_id, rejection_reason) may ever
 *    change, and only forward (no un-rejecting, no un-committing).
 *    DELETE is refused outright.
 *
 *  - A-§C.14 at the boundary: aevum_app can INSERT events (proposals
 *    are its only voice) but still holds no privilege on the ledger
 *    tables; the ingress that turns a proposal into an entry is
 *    Meridian's alone.
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
        DB::unprepared('CREATE EXTENSION IF NOT EXISTS pgcrypto');

        // ------------------------------------------------------------------
        // event_signers — each leg's registered Ed25519 verification key.
        // ------------------------------------------------------------------
        Schema::create('event_signers', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('source');
            $table->string('public_key'); // base64 Ed25519
            $table->string('status')->default('active');
            $table->timestampTz('registered_at');

            $table->unique(['source', 'status']);
        });

        DB::unprepared(<<<'SQL'
ALTER TABLE event_signers
    ADD CONSTRAINT event_signers_source_check CHECK (source IN ('aevum', 'meridian')),
    ADD CONSTRAINT event_signers_status_check CHECK (status IN ('active', 'retired'));
SQL);

        // ------------------------------------------------------------------
        // cross_system_events — the chained stream itself.
        // ------------------------------------------------------------------
        Schema::create('cross_system_events', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->bigInteger('seq')->generatedAs()->unique();
            $table->string('source');
            $table->string('kind');
            $table->jsonb('payload');
            $table->text('canonical_payload');
            $table->char('prev_hash', 64);
            $table->char('entry_hash', 64);
            $table->string('signature'); // base64 Ed25519 over entry_hash
            $table->string('idempotency_key')->unique();
            $table->string('status')->default('emitted');
            $table->string('result_transaction_id')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->timestampTz('created_at');

            $table->index(['kind', 'status']);
        });

        DB::unprepared(<<<'SQL'
ALTER TABLE cross_system_events
    ADD CONSTRAINT cross_system_events_source_check CHECK (source IN ('aevum', 'meridian')),
    ADD CONSTRAINT cross_system_events_kind_check CHECK (kind IN (
        'proposal.transfer', 'proposal.filter_verdict', 'proposal.policy_change',
        'proposal.currency_registration', 'confirmation.committed',
        'confirmation.rejected', 'reconciliation.alert'
    )),
    ADD CONSTRAINT cross_system_events_status_check CHECK (status IN (
        'emitted', 'validated', 'committed', 'rejected', 'reconciled'
    )),
    ADD CONSTRAINT cross_system_events_outcome_shape CHECK (
        (status <> 'committed' OR result_transaction_id IS NOT NULL)
        AND (status <> 'rejected' OR rejection_reason IS NOT NULL)
    );
SQL);

        // The chain guard: every INSERT must (a) link prev_hash to the
        // latest event's entry_hash (or genesis), and (b) carry an
        // entry_hash that RECOMPUTES from its own content — the DB does
        // the arithmetic itself; it does not trust the writer's hash.
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION cross_system_events_guard_chain()
RETURNS trigger
LANGUAGE plpgsql
AS $$
DECLARE
    latest_hash char(64);
    expected_hash char(64);
BEGIN
    SELECT entry_hash INTO latest_hash
    FROM cross_system_events
    ORDER BY seq DESC
    LIMIT 1;

    IF latest_hash IS NULL THEN
        latest_hash := repeat('0', 64);
    END IF;

    IF NEW.prev_hash <> latest_hash THEN
        RAISE EXCEPTION 'EVENT CHAIN: prev_hash % does not link to the latest chained event (expected %); a spliced or replayed link is rejected', NEW.prev_hash, latest_hash
            USING ERRCODE = 'check_violation';
    END IF;

    expected_hash := encode(digest(
        NEW.id || '|' || NEW.source || '|' || NEW.kind || '|'
        || NEW.canonical_payload || '|' || NEW.idempotency_key || '|' || NEW.prev_hash,
        'sha256'
    ), 'hex');

    IF NEW.entry_hash <> expected_hash THEN
        RAISE EXCEPTION 'EVENT CHAIN: entry_hash does not recompute from the event content; tampering is rejected at the door'
            USING ERRCODE = 'check_violation';
    END IF;

    RETURN NEW;
END;
$$;

CREATE TRIGGER trg_cross_system_events_guard_chain
    BEFORE INSERT ON cross_system_events
    FOR EACH ROW
    EXECUTE FUNCTION cross_system_events_guard_chain();
SQL);

        // Append-only: the chained content is immutable; only the
        // processing outcome advances, and only forward.
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION cross_system_events_guard_immutable()
RETURNS trigger
LANGUAGE plpgsql
AS $$
BEGIN
    IF TG_OP = 'DELETE' THEN
        RAISE EXCEPTION 'EVENT CHAIN: events are append-only; DELETE is refused'
            USING ERRCODE = 'check_violation';
    END IF;

    IF NEW.id <> OLD.id OR NEW.seq <> OLD.seq OR NEW.source <> OLD.source
        OR NEW.kind <> OLD.kind OR NEW.canonical_payload <> OLD.canonical_payload
        OR NEW.prev_hash <> OLD.prev_hash OR NEW.entry_hash <> OLD.entry_hash
        OR NEW.signature <> OLD.signature
        OR NEW.idempotency_key <> OLD.idempotency_key
        OR NEW.created_at <> OLD.created_at
        OR NEW.payload::text <> OLD.payload::text
    THEN
        RAISE EXCEPTION 'EVENT CHAIN: the chained content of an event is immutable; only the processing outcome may advance'
            USING ERRCODE = 'check_violation';
    END IF;

    IF OLD.status IN ('committed', 'rejected') AND NEW.status <> OLD.status
        AND NOT (OLD.status = 'committed' AND NEW.status = 'reconciled')
    THEN
        RAISE EXCEPTION 'EVENT CHAIN: a terminal outcome (%) cannot be rewritten to %', OLD.status, NEW.status
            USING ERRCODE = 'check_violation';
    END IF;

    IF OLD.result_transaction_id IS NOT NULL
        AND NEW.result_transaction_id IS DISTINCT FROM OLD.result_transaction_id
    THEN
        RAISE EXCEPTION 'EVENT CHAIN: a recorded outcome transaction is immutable'
            USING ERRCODE = 'check_violation';
    END IF;

    RETURN NEW;
END;
$$;

CREATE TRIGGER trg_cross_system_events_guard_immutable
    BEFORE UPDATE OR DELETE ON cross_system_events
    FOR EACH ROW
    EXECUTE FUNCTION cross_system_events_guard_immutable();
SQL);

        // ------------------------------------------------------------------
        // Grants. Aevum may append events and read the stream — its only
        // voice across the boundary. Its lack of ledger privilege was
        // established in DEV-4.4 and stays untouched here.
        // ------------------------------------------------------------------
        DB::unprepared(<<<'SQL'
GRANT SELECT, INSERT, UPDATE ON cross_system_events TO aevum_app;
GRANT SELECT, INSERT, UPDATE ON cross_system_events TO meridian_app;
GRANT SELECT ON event_signers TO aevum_app;
GRANT SELECT, INSERT, UPDATE ON event_signers TO meridian_app;
GRANT USAGE ON ALL SEQUENCES IN SCHEMA public TO aevum_app, meridian_app;
SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS trg_cross_system_events_guard_immutable ON cross_system_events');
        DB::unprepared('DROP TRIGGER IF EXISTS trg_cross_system_events_guard_chain ON cross_system_events');
        DB::unprepared('DROP FUNCTION IF EXISTS cross_system_events_guard_immutable()');
        DB::unprepared('DROP FUNCTION IF EXISTS cross_system_events_guard_chain()');
        Schema::dropIfExists('cross_system_events');
        Schema::dropIfExists('event_signers');
    }
};
