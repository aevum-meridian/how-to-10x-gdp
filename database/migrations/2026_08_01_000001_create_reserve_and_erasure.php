<?php

/**
 * SPDX-License-Identifier: LicenseRef-MVL-2.0 AND LicenseRef-AVL-2.0
 *
 * DEV-8.3 / DOCUMENT 8.3 — Reserve Attestation & Proof-of-Backing.
 * DEV-6.5 / DOCUMENT 6.5 — Data Retention, Erasure & Ledger-Tombstones.
 *
 * Reserve side (Meridian): a licensed custodian's signed, append-only,
 * replay-bounded attestation of reserves held. The issuance engine
 * refuses any reserve mint beyond the latest attested figure (already
 * structural in DEV-4.2); this migration supplies the attested figure a
 * verifiable home. Attestations are IMMUTABLE — a custodian can only add
 * a fresher attestation, never rewrite history.
 *
 * Erasure side (Joint): NO PII lives in immutable ledger entries. PII
 * lives off-ledger in pii_records, encrypted under a per-record key in
 * pii_encryption_keys. "Erasure" is crypto-shredding: destroy the record
 * and its key, leave an immutable tombstone. Three DB-level guards:
 *   1. A pii_record cannot be deleted without a tombstone already in
 *      place (shredding always leaves proof a fact occurred).
 *   2. A pii_record under an open attestation_dispute cannot be deleted
 *      (the legal hold), UNLESS a disclosed hold has reached its bounded
 *      maximum — the hold is narrow and time-bound, never indefinite.
 *   3. Tombstones are append-only: never updated, never deleted.
 *
 * Honest caveat (DOCUMENT 6.5 §6): crypto-shredding's deletion is
 * cryptographic, not physical. Encrypted copies may persist in backups;
 * what is destroyed is the key. The guarantee holds as long as the
 * cryptography holds — it must be stated as conditional, never absolute.
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
        // DEV-8.3 — reserve custodians ("tunnels are licensed institutions",
        // DOCUMENT 3.3): each custodian is registered with its Ed25519
        // verification key and its license reference before any attestation
        // from it is accepted.
        // ------------------------------------------------------------------
        Schema::create('reserve_custodians', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->char('currency_id', 26);
            $table->string('name', 128);
            $table->char('public_key', 64); // Ed25519 verify key, hex.
            $table->string('license_ref', 128);
            $table->timestampTz('revoked_at')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->foreign('currency_id')->references('id')->on('currencies');
            $table->unique(['currency_id', 'public_key']);
        });

        DB::unprepared(<<<'SQL'
ALTER TABLE reserve_custodians
    ADD CONSTRAINT reserve_custodians_name_nonempty CHECK (length(name) > 0),
    ADD CONSTRAINT reserve_custodians_license_nonempty CHECK (length(license_ref) > 0);
SQL);

        // ------------------------------------------------------------------
        // DEV-8.3 — reserve attestations: real-time, signed, append-only,
        // replay-bounded (unique nonce per custodian message). The attested
        // figure can never go negative and the record can never be edited:
        // a custodian corrects itself only by attesting again, fresher.
        // ------------------------------------------------------------------
        Schema::create('reserve_attestations', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->char('custodian_id', 26);
            $table->char('currency_id', 26);
            $table->bigInteger('attested_reserve_minor');
            $table->string('nonce', 128)->unique();
            $table->text('signature');
            $table->timestampTz('attested_at');
            $table->timestampTz('created_at')->useCurrent();

            $table->foreign('custodian_id')->references('id')->on('reserve_custodians');
            $table->foreign('currency_id')->references('id')->on('currencies');
            $table->index(['currency_id', 'attested_at']);
        });

        DB::unprepared(<<<'SQL'
ALTER TABLE reserve_attestations
    ADD CONSTRAINT reserve_attestations_amount_nonnegative
        CHECK (attested_reserve_minor >= 0),
    ADD CONSTRAINT reserve_attestations_signature_nonempty
        CHECK (length(signature) > 0);

-- Attestations are the proof-of-backing record: append-only forever.
CREATE OR REPLACE FUNCTION reserve_attestations_forbid_mutation()
RETURNS trigger AS $$
BEGIN
    RAISE EXCEPTION
        'RESERVE ATTESTATION: the attestation record is append-only. A custodian corrects itself by attesting again, never by rewriting history.';
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER trg_reserve_attestations_immutable
    BEFORE UPDATE OR DELETE ON reserve_attestations
    FOR EACH ROW EXECUTE FUNCTION reserve_attestations_forbid_mutation();
SQL);

        // ------------------------------------------------------------------
        // DEV-6.5 — the off-ledger PII vault. Each record's payload is
        // encrypted under a per-record symmetric key held in a separate
        // table. Nothing on-ledger references a person by anything but an
        // opaque ULID; THIS is the only mapping, and it is erasable.
        // ------------------------------------------------------------------
        Schema::create('pii_encryption_keys', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->text('key_material'); // base64 secretbox key; destroyed on shred.
            $table->timestampTz('created_at')->useCurrent();
        });

        Schema::create('pii_records', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->char('subject_reference', 26); // opaque ULID (account/identity), no FK by design.
            $table->string('purpose', 64);
            $table->text('ciphertext'); // secretbox(nonce || box), base64. Never plaintext.
            $table->char('key_id', 26);
            $table->timestampTz('created_at')->useCurrent();

            $table->foreign('key_id')->references('id')->on('pii_encryption_keys');
            $table->index('subject_reference');
        });

        DB::unprepared(<<<'SQL'
ALTER TABLE pii_records
    ADD CONSTRAINT pii_records_purpose_nonempty CHECK (length(purpose) > 0),
    ADD CONSTRAINT pii_records_ciphertext_nonempty CHECK (length(ciphertext) > 0);
SQL);

        // ------------------------------------------------------------------
        // DEV-6.5 — immutable tombstones: proof a fact occurred without a
        // path to who it concerned. subject_digest is a one-way hash, not
        // a reversible reference.
        // ------------------------------------------------------------------
        Schema::create('erasure_tombstones', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->char('pii_record_id', 26)->unique(); // no FK: the record it marks is destroyed.
            $table->char('subject_digest', 64); // sha256 hex of the shredded record id + purpose.
            $table->string('reason', 256);
            $table->timestampTz('shredded_at')->useCurrent();
        });

        // ------------------------------------------------------------------
        // DEV-6.5 — bounded, DISCLOSED legal holds. A hold names the open
        // dispute, states the reason to the requesting person, and carries
        // a hard expiry (the "defined maximum"). The disclosure obligation
        // and the bound are constitutional, not procedural (DOCUMENT 6.5
        // §3, §6 — the Coercion-Resistance intersection).
        // ------------------------------------------------------------------
        Schema::create('erasure_holds', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            // No FK to pii_records BY DESIGN: the hold is the audit trail
            // of a disclosed deferral and must SURVIVE the record's
            // eventual crypto-shredding, exactly like the tombstone.
            $table->char('pii_record_id', 26);
            $table->char('dispute_id', 26);
            $table->string('disclosed_reason', 512);
            $table->timestampTz('hold_expires_at');
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('released_at')->nullable();

            $table->foreign('dispute_id')->references('id')->on('attestation_disputes');
            $table->index('pii_record_id');
        });

        DB::unprepared(<<<'SQL'
ALTER TABLE erasure_holds
    ADD CONSTRAINT erasure_holds_reason_nonempty CHECK (length(disclosed_reason) > 0),
    ADD CONSTRAINT erasure_holds_bounded CHECK (hold_expires_at > created_at);

-- Tombstones are append-only: the proof that a shred happened can never
-- itself be quietly removed or rewritten.
CREATE OR REPLACE FUNCTION erasure_tombstones_forbid_mutation()
RETURNS trigger AS $$
BEGIN
    RAISE EXCEPTION
        'ERASURE TOMBSTONE: tombstones are immutable. The proof that a shred occurred can never be edited or removed.';
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER trg_erasure_tombstones_immutable
    BEFORE UPDATE OR DELETE ON erasure_tombstones
    FOR EACH ROW EXECUTE FUNCTION erasure_tombstones_forbid_mutation();

-- The shred guard: a pii_record may be deleted ONLY when
--   (a) an immutable tombstone for it already exists (shredding always
--       leaves proof), AND
--   (b) no open attestation_dispute concerns its subject — the legal
--       hold — UNLESS a disclosed hold on this record has reached its
--       bounded maximum (holds are narrow and time-bound, never a
--       channel for indefinite compelled retention).
CREATE OR REPLACE FUNCTION pii_records_guard_shred()
RETURNS trigger AS $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM erasure_tombstones t WHERE t.pii_record_id = OLD.id
    ) THEN
        RAISE EXCEPTION
            'ERASURE: crypto-shredding requires an immutable tombstone in place before the record is destroyed.';
    END IF;

    IF EXISTS (
        SELECT 1
        FROM attestation_disputes ad
        JOIN attestations a ON a.id = ad.attestation_id
        WHERE a.recipient_account_id = OLD.subject_reference
          AND ad.status NOT IN ('resolved_fraud', 'resolved_valid')
    ) AND NOT EXISTS (
        SELECT 1
        FROM erasure_holds h
        WHERE h.pii_record_id = OLD.id
          AND h.hold_expires_at <= now()
    ) THEN
        RAISE EXCEPTION
            'LEGAL HOLD: this record is evidence in an open dispute and cannot be shredded until the case closes or the disclosed hold reaches its bounded maximum.';
    END IF;

    RETURN OLD;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER trg_pii_records_guard_shred
    BEFORE DELETE ON pii_records
    FOR EACH ROW EXECUTE FUNCTION pii_records_guard_shred();
SQL);

        // ------------------------------------------------------------------
        // Grants. Attestations are the user's right to proof-of-backing:
        // aevum_app may READ them (verification), never write them. The PII
        // vault is Meridian-side only; aevum_app has NO path to it at all.
        // ------------------------------------------------------------------
        DB::unprepared(<<<'SQL'
GRANT SELECT, INSERT, UPDATE ON reserve_custodians TO meridian_app;
GRANT SELECT, INSERT ON reserve_attestations TO meridian_app;
GRANT SELECT ON reserve_custodians, reserve_attestations TO aevum_app;
GRANT SELECT ON reserve_attestations TO meridian_policy_engine;
REVOKE INSERT, UPDATE, DELETE, TRUNCATE ON reserve_attestations FROM aevum_app;

GRANT SELECT, INSERT, DELETE ON pii_records, pii_encryption_keys TO meridian_app;
GRANT SELECT, INSERT ON erasure_tombstones TO meridian_app;
GRANT SELECT, INSERT, UPDATE ON erasure_holds TO meridian_app;
REVOKE ALL ON pii_records, pii_encryption_keys, erasure_tombstones, erasure_holds FROM aevum_app;
SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
DROP TRIGGER IF EXISTS trg_pii_records_guard_shred ON pii_records;
DROP TRIGGER IF EXISTS trg_erasure_tombstones_immutable ON erasure_tombstones;
DROP TRIGGER IF EXISTS trg_reserve_attestations_immutable ON reserve_attestations;
DROP FUNCTION IF EXISTS pii_records_guard_shred();
DROP FUNCTION IF EXISTS erasure_tombstones_forbid_mutation();
DROP FUNCTION IF EXISTS reserve_attestations_forbid_mutation();
SQL);

        Schema::dropIfExists('erasure_holds');
        Schema::dropIfExists('erasure_tombstones');
        Schema::dropIfExists('pii_records');
        Schema::dropIfExists('pii_encryption_keys');
        Schema::dropIfExists('reserve_attestations');
        Schema::dropIfExists('reserve_custodians');
    }
};
