<?php

/**
 * SPDX-License-Identifier: LicenseRef-MVL-2.0
 *
 * DEV-4.1 — MERIDIAN LEDGER CORE migration.
 * Enforces at the DATABASE layer (the first of the three enforcement
 * points, per DOCUMENT 0.1 and DOCUMENT 4.1):
 *
 *   I1 (conservation)      — deferred constraint trigger: at COMMIT, the
 *                            per-currency sum of a transaction's entries
 *                            must be exactly zero.
 *   I2 (balance integrity) — entries.balance_after is computed at insert,
 *                            inside the same atomic transaction, by a
 *                            trigger that also row-locks and maintains
 *                            accounts.balance_minor; a discrepancy table
 *                            (must stay empty) receives nightly recompute
 *                            results.
 *   I5 (append-only)       — REVOKE UPDATE, DELETE on entries/transactions
 *                            from application roles + triggers raising on
 *                            any UPDATE/DELETE attempt (the trigger binds
 *                            even superusers, so it is the testable layer).
 *   I6 (no punitive debit) — a negative entry against a personal account
 *                            must carry a holder authorization reference;
 *                            the ONLY non-holder path is the arbitration
 *                            reversal, and UNTIL THE DISPUTE ENGINE
 *                            (DEV-4.3) SHIPS, THAT PATH DOES NOT EXIST:
 *                            this migration's trigger rejects the
 *                            arbitration_reversal kind entirely. DEV-4.3
 *                            replaces the function with the full
 *                            I6-revised predicate. A debit referencing
 *                            neither is structurally impossible.
 *   I7 (issuance-only)     — a dedicated meridian_policy_engine DB role is
 *                            created here with NO INSERT/UPDATE privilege
 *                            on entries/transactions (DEV-4.5 grants it
 *                            its own four tables only).
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
        Schema::create('currencies', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->string('code', 32)->unique();
            $table->string('name');
            $table->string('family', 32); // reserve | distributed | contribution
            $table->unsignedSmallInteger('decimals')->default(2);
            $table->char('issuance_policy_id', 26)->nullable();
            $table->boolean('is_transferable')->default(true);
            $table->boolean('is_active')->default(true);
            $table->jsonb('governance_metadata')->default('{}');
            $table->timestampTz('created_at')->useCurrent();
        });

        DB::statement("ALTER TABLE currencies ADD CONSTRAINT currencies_family_check CHECK (family IN ('reserve','distributed','contribution'))");

        Schema::create('accounts', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->string('owner_id')->nullable();
            $table->string('owner_type', 32); // person | institution | system
            $table->char('currency_id', 26);
            $table->string('type', 32); // asset|liability|equity|income|expense|system
            $table->string('system_role', 32)->nullable(); // issuance|burn|fee|reservation
            $table->string('status', 32)->default('active');
            $table->bigInteger('balance_minor')->default(0);
            $table->timestampTz('created_at')->useCurrent();

            $table->foreign('currency_id')->references('id')->on('currencies');
            $table->unique(['currency_id', 'system_role']);
        });

        DB::statement("ALTER TABLE accounts ADD CONSTRAINT accounts_owner_type_check CHECK (owner_type IN ('person','institution','system'))");
        DB::statement("ALTER TABLE accounts ADD CONSTRAINT accounts_type_check CHECK (type IN ('asset','liability','equity','income','expense','system'))");
        DB::statement("ALTER TABLE accounts ADD CONSTRAINT accounts_system_role_check CHECK (system_role IS NULL OR system_role IN ('issuance','burn','fee','reservation'))");
        DB::statement("ALTER TABLE accounts ADD CONSTRAINT accounts_system_owner_check CHECK ((system_role IS NULL) OR (owner_type = 'system'))");

        Schema::create('transactions', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->string('kind', 32);
            $table->string('status', 32)->default('posted');
            $table->char('reverses_transaction_id', 26)->nullable();
            $table->char('reverses_mint_transaction_id', 26)->nullable();
            $table->char('arbitration_case_id', 26)->nullable();
            $table->string('idempotency_key')->unique();
            $table->jsonb('metadata')->default('{}');
            $table->timestampTz('posted_at')->useCurrent();
            $table->timestampTz('created_at')->useCurrent();
        });

        // Self-referencing FKs added after the primary key exists.
        DB::statement('ALTER TABLE transactions ADD CONSTRAINT transactions_reverses_txn_fk FOREIGN KEY (reverses_transaction_id) REFERENCES transactions (id)');
        DB::statement('ALTER TABLE transactions ADD CONSTRAINT transactions_reverses_mint_fk FOREIGN KEY (reverses_mint_transaction_id) REFERENCES transactions (id)');

        DB::statement("ALTER TABLE transactions ADD CONSTRAINT transactions_kind_check CHECK (kind IN ('transfer','holder_spend','mint','burn','reversal','arbitration_reversal','settlement','reservation'))");
        DB::statement("ALTER TABLE transactions ADD CONSTRAINT transactions_status_check CHECK (status = 'posted')");
        // I6 shape check: an arbitration reversal must reference BOTH a
        // specific mint transaction AND an arbitration case; nothing else
        // may carry those references.
        DB::statement(<<<'SQL'
            ALTER TABLE transactions ADD CONSTRAINT transactions_arbitration_shape_check CHECK (
                (kind = 'arbitration_reversal' AND reverses_mint_transaction_id IS NOT NULL AND arbitration_case_id IS NOT NULL)
                OR
                (kind <> 'arbitration_reversal' AND reverses_mint_transaction_id IS NULL AND arbitration_case_id IS NULL)
            )
        SQL);

        Schema::create('entries', function (Blueprint $table): void {
            $table->id();
            $table->char('transaction_id', 26);
            $table->char('account_id', 26);
            $table->char('currency_id', 26);
            $table->bigInteger('amount'); // signed bigint minor units — never float
            $table->bigInteger('balance_after');
            $table->string('holder_authorization_ref')->nullable();
            $table->unsignedBigInteger('reverses_entry_id')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->foreign('transaction_id')->references('id')->on('transactions');
            $table->foreign('account_id')->references('id')->on('accounts');
            $table->foreign('currency_id')->references('id')->on('currencies');
            $table->index(['account_id', 'id']);
        });

        DB::statement('ALTER TABLE entries ADD CONSTRAINT entries_reverses_entry_fk FOREIGN KEY (reverses_entry_id) REFERENCES entries (id)');

        DB::statement('ALTER TABLE entries ADD CONSTRAINT entries_amount_nonzero_check CHECK (amount <> 0)');

        // The must-stay-empty discrepancy table (I2 nightly recompute;
        // discrepancies ALERT, NEVER auto-correct — DOCUMENT 4.1).
        Schema::create('ledger_discrepancies', function (Blueprint $table): void {
            $table->id();
            $table->string('check_kind', 64); // balance_recompute | supply_proof
            $table->char('account_id', 26)->nullable();
            $table->char('currency_id', 26)->nullable();
            $table->bigInteger('expected_minor');
            $table->bigInteger('actual_minor');
            $table->timestampTz('detected_at')->useCurrent();
        });

        // ------------------------------------------------------------------
        // I2 — balance_after computed at insert, account row-locked.
        // ------------------------------------------------------------------
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION ledger_compute_balance_after()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            DECLARE
                current_balance bigint;
                account_currency char(26);
            BEGIN
                -- Row-lock the account: serializes concurrent entry inserts
                -- against the same account (DOCUMENT 4.1 concurrency model).
                SELECT balance_minor, currency_id INTO current_balance, account_currency
                FROM accounts WHERE id = NEW.account_id FOR UPDATE;

                IF NOT FOUND THEN
                    RAISE EXCEPTION 'LEDGER: account % does not exist', NEW.account_id;
                END IF;

                IF account_currency <> NEW.currency_id THEN
                    RAISE EXCEPTION 'LEDGER: entry currency % does not match account currency %', NEW.currency_id, account_currency;
                END IF;

                NEW.balance_after := current_balance + NEW.amount;

                UPDATE accounts SET balance_minor = NEW.balance_after WHERE id = NEW.account_id;

                RETURN NEW;
            END;
            $$;
        SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER trg_entries_balance_after
            BEFORE INSERT ON entries
            FOR EACH ROW
            EXECUTE FUNCTION ledger_compute_balance_after();
        SQL);

        // ------------------------------------------------------------------
        // I6 — the no-punitive-debit trigger (pre-Dispute-Engine form).
        // A negative entry against a personal account must carry a holder
        // authorization reference. The arbitration_reversal kind — the only
        // permitted non-holder path — DOES NOT EXIST until DEV-4.3 ships;
        // it is rejected outright here, and DEV-4.3 replaces this function
        // with the full I6-revised predicate. There is no third path.
        // ------------------------------------------------------------------
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION ledger_guard_personal_debit()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            DECLARE
                acct_owner_type text;
                txn_kind text;
            BEGIN
                IF NEW.amount >= 0 THEN
                    RETURN NEW;
                END IF;

                SELECT owner_type INTO acct_owner_type FROM accounts WHERE id = NEW.account_id;

                IF acct_owner_type <> 'person' THEN
                    RETURN NEW;
                END IF;

                SELECT kind INTO txn_kind FROM transactions WHERE id = NEW.transaction_id;

                IF txn_kind = 'arbitration_reversal' THEN
                    -- Pre-DEV-4.3: no arbitration path exists. Rejected.
                    RAISE EXCEPTION 'I6: the arbitration reversal path does not exist until the Dispute Engine (DEV-4.3) is installed; punitive debits are structurally impossible';
                END IF;

                IF NEW.holder_authorization_ref IS NULL THEN
                    RAISE EXCEPTION 'I6/I10: a debit against a personal account requires holder authorization; a debit referencing neither a holder authorization nor a closed arbitration case + specific fraudulent mint is a punitive debit and is always rejected';
                END IF;

                RETURN NEW;
            END;
            $$;
        SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER trg_entries_personal_debit_guard
            BEFORE INSERT ON entries
            FOR EACH ROW
            EXECUTE FUNCTION ledger_guard_personal_debit();
        SQL);

        // ------------------------------------------------------------------
        // I1 — conservation: deferred constraint trigger, fires at COMMIT.
        // ------------------------------------------------------------------
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION ledger_check_transaction_balanced()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            DECLARE
                bad_currency char(26);
                bad_sum bigint;
            BEGIN
                SELECT currency_id, SUM(amount) INTO bad_currency, bad_sum
                FROM entries
                WHERE transaction_id = NEW.transaction_id
                GROUP BY currency_id
                HAVING SUM(amount) <> 0
                LIMIT 1;

                IF bad_currency IS NOT NULL THEN
                    RAISE EXCEPTION 'I1: transaction % violates conservation — entries for currency % sum to % (must be 0)',
                        NEW.transaction_id, bad_currency, bad_sum;
                END IF;

                RETURN NEW;
            END;
            $$;
        SQL);

        DB::unprepared(<<<'SQL'
            CREATE CONSTRAINT TRIGGER trg_entries_conservation
            AFTER INSERT ON entries
            DEFERRABLE INITIALLY DEFERRED
            FOR EACH ROW
            EXECUTE FUNCTION ledger_check_transaction_balanced();
        SQL);

        // ------------------------------------------------------------------
        // I5 — append-only: triggers raising on any UPDATE/DELETE. These
        // bind every role, superusers included, and are therefore the
        // testable layer; the REVOKEs below deny app roles even the
        // attempt.
        // ------------------------------------------------------------------
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION ledger_forbid_mutation()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            BEGIN
                RAISE EXCEPTION 'I5: % on % is forbidden — the ledger is append-only; corrections are new, additive, reversing entries', TG_OP, TG_TABLE_NAME;
            END;
            $$;
        SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER trg_entries_append_only
            BEFORE UPDATE OR DELETE ON entries
            FOR EACH ROW
            EXECUTE FUNCTION ledger_forbid_mutation();
        SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER trg_transactions_append_only
            BEFORE UPDATE OR DELETE ON transactions
            FOR EACH ROW
            EXECUTE FUNCTION ledger_forbid_mutation();
        SQL);

        // ------------------------------------------------------------------
        // Roles and privileges: the application role may INSERT/SELECT but
        // never UPDATE/DELETE (I5); the Policy Engine role has NO
        // INSERT/UPDATE on entries/transactions at all (I7 — DEV-4.5 will
        // grant it only its own four tables).
        // ------------------------------------------------------------------
        DB::unprepared(<<<'SQL'
            DO $$
            BEGIN
                IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'meridian_app') THEN
                    CREATE ROLE meridian_app NOLOGIN;
                END IF;
                IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'meridian_policy_engine') THEN
                    CREATE ROLE meridian_policy_engine NOLOGIN;
                END IF;
                IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'meridian_membrane') THEN
                    CREATE ROLE meridian_membrane NOLOGIN;
                END IF;
            END
            $$;
        SQL);

        DB::unprepared(<<<'SQL'
            GRANT SELECT, INSERT ON currencies, accounts, transactions, entries TO meridian_app;
            GRANT UPDATE (balance_minor) ON accounts TO meridian_app;
            GRANT SELECT, INSERT ON ledger_discrepancies TO meridian_app;
            REVOKE UPDATE, DELETE, TRUNCATE ON entries FROM meridian_app;
            REVOKE UPDATE, DELETE, TRUNCATE ON transactions FROM meridian_app;

            GRANT SELECT ON currencies, accounts, transactions, entries TO meridian_policy_engine;
            REVOKE INSERT, UPDATE, DELETE, TRUNCATE ON entries FROM meridian_policy_engine;
            REVOKE INSERT, UPDATE, DELETE, TRUNCATE ON transactions FROM meridian_policy_engine;
            REVOKE INSERT, UPDATE, DELETE, TRUNCATE ON accounts FROM meridian_policy_engine;

            GRANT SELECT ON currencies, accounts, transactions, entries TO meridian_membrane;
            REVOKE INSERT, UPDATE, DELETE, TRUNCATE ON entries FROM meridian_membrane;
            REVOKE INSERT, UPDATE, DELETE, TRUNCATE ON transactions FROM meridian_membrane;
        SQL);

        // Idempotency keys — durable table half of the Redis+table pair.
        Schema::create('idempotency_keys', function (Blueprint $table): void {
            $table->string('key')->primary();
            $table->char('transaction_id', 26);
            $table->timestampTz('created_at')->useCurrent();

            $table->foreign('transaction_id')->references('id')->on('transactions');
        });

        DB::unprepared('GRANT SELECT, INSERT ON idempotency_keys TO meridian_app');
    }

    public function down(): void
    {
        Schema::dropIfExists('idempotency_keys');
        Schema::dropIfExists('ledger_discrepancies');
        Schema::dropIfExists('entries');
        Schema::dropIfExists('transactions');
        Schema::dropIfExists('accounts');
        Schema::dropIfExists('currencies');
        DB::unprepared('DROP FUNCTION IF EXISTS ledger_compute_balance_after CASCADE');
        DB::unprepared('DROP FUNCTION IF EXISTS ledger_guard_personal_debit CASCADE');
        DB::unprepared('DROP FUNCTION IF EXISTS ledger_check_transaction_balanced CASCADE');
        DB::unprepared('DROP FUNCTION IF EXISTS ledger_forbid_mutation CASCADE');
    }
};
