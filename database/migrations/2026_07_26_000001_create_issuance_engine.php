<?php

/**
 * SPDX-License-Identifier: LicenseRef-MVL-2.0
 *
 * DEV-4.2 — ISSUANCE & PoVC ENGINE schema. Enforces at the DATABASE layer:
 *
 *  - I11 (No Core Riba Issuance): a CHECK on `issuance_policies` rejecting
 *    any row where all four Core-Riba conjuncts hold simultaneously
 *    (DOCUMENT 0.1 I11, DOCUMENT 2.1 §6.1).
 *  - I4 (Quorum Minting): unique `attestations.nonce`; a trigger requiring
 *    `quorum_met = true ∧ expires_at > now()` before any mint transaction
 *    may be referenced, and forbidding double-consumption of a nonce.
 *  - I8 (No Sensitive-Data Minting): a PostgreSQL EVENT TRIGGER that
 *    blocks any DDL introducing an identifiable biometric/health/neural
 *    column — the schema-rule half of the I8 enforcement; the CI migration
 *    scan (NoSensitivePIIMigrationTest) is the other half.
 *  - I7 boundary: the Policy Engine role may write `issuance_policies`
 *    (future minting) and NOTHING in the ledger.
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
        // I8 — the schema rule MUST exist BEFORE the tables, so even this
        // migration's own DDL is subject to it.
        // ------------------------------------------------------------------
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION issuance_forbid_sensitive_columns() RETURNS event_trigger AS $$
DECLARE
    obj record;
    col record;
BEGIN
    FOR obj IN SELECT * FROM pg_event_trigger_ddl_commands()
        WHERE command_tag IN ('CREATE TABLE', 'ALTER TABLE')
    LOOP
        FOR col IN
            SELECT attname FROM pg_attribute
            WHERE attrelid = obj.objid AND attnum > 0 AND NOT attisdropped
        LOOP
            IF col.attname ~* '(biometric|fingerprint|retina|iris_scan|face_geometry|face_template|voiceprint|gait|dna|genome|genetic|health_record|diagnosis|medical|blood_|heart_rate|neural|eeg|brainwave|brain_signal)' THEN
                RAISE EXCEPTION
                    'I8: column "%" is an identifiable biometric/health/neural column and may never exist in this schema; only ZK commitments or minimized-disclosure proofs are storable',
                    col.attname;
            END IF;
        END LOOP;
    END LOOP;
END;
$$ LANGUAGE plpgsql;

DROP EVENT TRIGGER IF EXISTS trg_i8_sensitive_columns;
CREATE EVENT TRIGGER trg_i8_sensitive_columns
    ON ddl_command_end
    WHEN TAG IN ('CREATE TABLE', 'ALTER TABLE')
    EXECUTE FUNCTION issuance_forbid_sensitive_columns();
SQL);

        // ------------------------------------------------------------------
        // issuance_policies — the five typed Core-Riba flags make I11 a
        // DB-checkable property rather than a judgment buried in code.
        // ------------------------------------------------------------------
        Schema::create('issuance_policies', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->char('currency_id', 26)->unique();
            $table->string('type', 32);
            $table->jsonb('params')->default('{}');
            $table->bigInteger('max_supply')->nullable();
            $table->jsonb('rate_limit')->nullable();
            $table->jsonb('decay_rule')->nullable();
            // The five typed Core-Riba flags (DOCUMENT 4.2, DOCUMENT 2.1 §6):
            $table->string('base_kind', 32);
            $table->string('increase_kind', 32);
            $table->boolean('risk_bearing');
            $table->boolean('value_creating');
            $table->boolean('extracts_from_counterparty');
            $table->timestampTz('created_at')->useCurrent();

            $table->foreign('currency_id')->references('id')->on('currencies');
        });

        DB::unprepared(<<<'SQL'
ALTER TABLE issuance_policies
    ADD CONSTRAINT issuance_policies_type_check
        CHECK (type IN ('reserve_1to1', 'bridge', 'povc')),
    ADD CONSTRAINT issuance_policies_base_kind_check
        CHECK (base_kind IN ('money', 'same_kind_fungible', 'real_asset', 'service', 'contribution')),
    ADD CONSTRAINT issuance_policies_increase_kind_check
        CHECK (increase_kind IN ('none', 'prefixed_guaranteed', 'profit_and_loss_share', 'rent', 'service_fee', 'staking_reward', 'demurrage')),
    -- I11: reject any row where ALL FOUR Core-Riba conjuncts hold
    -- simultaneously (DOCUMENT 0.1 I11 / DOCUMENT 2.1 §6.1):
    --   base ∈ {money, same_kind_fungible} ∧ increase = prefixed_guaranteed
    --   ∧ ¬risk_bearing ∧ ¬value_creating ∧ extracts_from_counterparty
    ADD CONSTRAINT issuance_policies_no_core_riba CHECK (NOT (
        base_kind IN ('money', 'same_kind_fungible')
        AND increase_kind = 'prefixed_guaranteed'
        AND risk_bearing = false
        AND value_creating = false
        AND extracts_from_counterparty = true
    )),
    ADD CONSTRAINT issuance_policies_max_supply_positive
        CHECK (max_supply IS NULL OR max_supply > 0);
SQL);

        // ------------------------------------------------------------------
        // verifiers — the registered attester set for PoVC quorums.
        // rotation_group encodes independence: M signatures must come from
        // M DISTINCT rotation groups, not M keys held by one party.
        // ------------------------------------------------------------------
        Schema::create('verifiers', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->string('name');
            $table->text('pubkey'); // base64 Ed25519 public key
            $table->string('family_scope', 32);
            $table->decimal('reputation', 8, 4)->default(0);
            $table->string('status', 16)->default('active');
            $table->string('rotation_group', 64);
            $table->bigInteger('bond')->default(0);
            $table->timestampTz('created_at')->useCurrent();
        });

        DB::unprepared(<<<'SQL'
ALTER TABLE verifiers
    ADD CONSTRAINT verifiers_status_check
        CHECK (status IN ('active', 'suspended', 'retired')),
    ADD CONSTRAINT verifiers_bond_nonnegative CHECK (bond >= 0);
SQL);

        // ------------------------------------------------------------------
        // attestations — subject_proof is a ZK commitment or hashed
        // personhood ref, NEVER raw PII (I8). nonce unique (I4 replay wall).
        // ------------------------------------------------------------------
        Schema::create('attestations', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->char('currency_id', 26);
            $table->char('recipient_account_id', 26);
            $table->text('subject_proof');
            $table->bigInteger('amount_minor');
            $table->string('nonce', 128)->unique();
            $table->timestampTz('expires_at');
            $table->jsonb('attester_set')->default('[]');
            $table->jsonb('signatures')->default('[]');
            $table->boolean('quorum_met')->default(false);
            $table->char('minted_transaction_id', 26)->nullable();
            $table->string('status', 16)->default('pending');
            $table->timestampTz('created_at')->useCurrent();

            $table->foreign('currency_id')->references('id')->on('currencies');
            $table->foreign('recipient_account_id')->references('id')->on('accounts');
            $table->foreign('minted_transaction_id')->references('id')->on('transactions');
        });

        DB::unprepared(<<<'SQL'
ALTER TABLE attestations
    ADD CONSTRAINT attestations_status_check
        CHECK (status IN ('pending', 'minted', 'rejected', 'expired', 'disputed')),
    ADD CONSTRAINT attestations_amount_positive CHECK (amount_minor > 0),
    -- I4 shape: a minted attestation must have met quorum.
    ADD CONSTRAINT attestations_minted_requires_quorum
        CHECK (minted_transaction_id IS NULL OR quorum_met = true);

-- I4 trigger: an attestation may be consumed by a mint EXACTLY ONCE, only
-- with quorum met, and only before expiry. Fired on the same UPDATE that
-- records the mint reference, inside the mint's atomic transaction.
CREATE OR REPLACE FUNCTION attestations_guard_mint() RETURNS trigger AS $$
BEGIN
    IF NEW.minted_transaction_id IS DISTINCT FROM OLD.minted_transaction_id
       AND NEW.minted_transaction_id IS NOT NULL THEN
        IF OLD.minted_transaction_id IS NOT NULL THEN
            RAISE EXCEPTION 'I4: attestation nonce % has already been consumed by mint %; replay rejected',
                OLD.nonce, OLD.minted_transaction_id;
        END IF;
        IF NEW.quorum_met IS DISTINCT FROM true THEN
            RAISE EXCEPTION 'I4: an attestation may not be consumed by a mint unless quorum_met = true';
        END IF;
        IF NEW.expires_at <= now() THEN
            RAISE EXCEPTION 'I4: expired attestation (expires_at %) may not mint', NEW.expires_at;
        END IF;
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER trg_attestations_guard_mint
    BEFORE UPDATE ON attestations
    FOR EACH ROW EXECUTE FUNCTION attestations_guard_mint();
SQL);

        // ------------------------------------------------------------------
        // I7 boundary grants: the Policy Engine role may write
        // issuance_policies (FUTURE minting only) and nothing in the ledger.
        // meridian_app operates the issuance tables normally.
        // ------------------------------------------------------------------
        DB::unprepared(<<<'SQL'
GRANT SELECT, INSERT ON issuance_policies TO meridian_app;
GRANT SELECT, INSERT, UPDATE ON attestations TO meridian_app;
GRANT SELECT, INSERT, UPDATE ON verifiers TO meridian_app;

GRANT SELECT, INSERT, UPDATE ON issuance_policies TO meridian_policy_engine;
GRANT SELECT ON attestations TO meridian_policy_engine;
GRANT SELECT ON verifiers TO meridian_policy_engine;

GRANT SELECT ON issuance_policies, attestations, verifiers TO meridian_membrane;
SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP EVENT TRIGGER IF EXISTS trg_i8_sensitive_columns');
        Schema::dropIfExists('attestations');
        Schema::dropIfExists('verifiers');
        Schema::dropIfExists('issuance_policies');
        DB::unprepared('DROP FUNCTION IF EXISTS issuance_forbid_sensitive_columns(); DROP FUNCTION IF EXISTS attestations_guard_mint();');
    }
};
