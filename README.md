# Meridian × Aevum

**A double-entry system of record (Meridian) and a system of engagement (Aevum), built so that money is structurally incapable of punishment.**

**Author of the specification:** Maher Kadmer Kaddoussi
**Repository:** [aevum-meridian/how-to-10x-gdp](https://github.com/aevum-meridian/how-to-10x-gdp)
**Specification:** the DEV-series consolidated build specification and Documents 0.1–10.7 in [`docs/`](docs/) — the authoritative source this codebase implements.
**Licenses:** `LicenseRef-MVL-2.0` (Meridian), `LicenseRef-AVL-2.0` (Aevum); both at joint boundaries. See [`docs/`](docs/) for full license texts.

> *“Money that measures what we become cannot be a weapon in the hand of a tyrant, because we have made it incapable of punishment.”* — the author's introduction

---

## Honest maturity statement (read this first)

**Nothing in this repository is Shipped.** Every capability carries a maturity label from the
Maturity & Abandonment Ledger (DOCUMENT 3.4), surfaced at `GET /api/v1/maturity` — the binding
check every other surface must consult. The invariant suite is green and every invariant is
triple-enforced, but *Shipped* additionally requires the external audits of DOCUMENT 9.4 and
production operation. Presenting a Research-tier capability as available violates
AVL-2.0 §A-§C.13, and this repository refuses to do it — in its API, its OpenAPI document,
and this README.

- Current labels: **InDevelopment** (ledger core, non-punishment spine, membrane, Core-Riba
  refusal, crypto-shredding erasure, regulated peg, public API v1, …) and **Research**
  (PoVC quorum minting, personhood layer, dispute/arbitration, policy engine, settlement,
  event contract, crisis response, contribution credits, $FLUX, …).
- One row is **DeprecatedRemoved** and is never deleted: the $FOCUS EEG currency, retired on
  the no-neural-data principle.
- The eight-billion-users row is Research, and its abandonment criterion revises the claim
  *downward* rather than relaxing the safety floors.

---

## The one rule above all rules

Only the Dispute Engine's single method, `DisputeService::applyArbitrationReversal()`, may
debit a personal contribution balance, and only under the I6-revised predicate. Punitive
debits are **structurally impossible three ways over**:

1. **Database:** the PostgreSQL trigger `trg_entries_personal_debit_guard` rejects the row.
2. **Service:** `LedgerService::post()` refuses the draft before it reaches the database.
3. **Test:** Pest property tests (`NonPunishmentTest`, and the prose-logic gate below)
   prove both walls hold, continuously.

Emergency is never a license to violate the spine.

## The invariants (I1–I11)

DOCUMENT 0.1 governs above all. Every invariant is enforced at least three times —
a database constraint or trigger, a service guard, and a property test:

| | Invariant | Database wall (examples) |
|---|---|---|
| I1 | Conservation: every transaction's entries sum to zero | `trg_entries_conservation` |
| I2 | Balance integrity: `balance_after` chains reconcile | `trg_entries_balance_after` |
| I3 | Supply integrity: total balances sum to zero per currency | proven by `proveSupplyIntegrity()` |
| I4 | Minting requires a valid PoVC quorum attestation | `attestations_nonce_unique`, `trg_attestations_guard_mint` |
| I5 | Append-only ledger: no UPDATE/DELETE of entries or transactions | `trg_entries_append_only`, `trg_transactions_append_only` |
| I6 | Non-punishment: no punitive debit of personal balances | `trg_entries_personal_debit_guard` |
| I7 | Policy engine is powerless over the ledger | `meridian_policy_engine` role has no INSERT privilege |
| I8 | No sensitive-data columns may ever be added | event trigger `trg_i8_sensitive_columns` |
| I9 | Personhood lives outside Meridian, behind the GAS adapter | `identities` table boundary |
| I10 | Settlement can coordinate but never debit punitively | shares the I6 trigger |
| I11 | Core Riba refusal: no interest-bearing core policies | `issuance_policies_no_core_riba` |

### The prose-logic agreement gate

A machine-readable invariant registry
(`app/Domain/Joint/Constitution/InvariantRegistry.php`) is parsed against DOCUMENT 0.1
**verbatim, character for character, in four directions** at test time
(`tests/Invariants/ProseLogicAgreementTest.php`):

1. prose → registry (every invariant in the document exists in code),
2. registry → prose (nothing invented),
3. registry → the §XREF matrix and the named test files,
4. registry → the **live database catalogs** (`pg_trigger`, `pg_constraint`, `pg_indexes`,
   `pg_event_trigger`, `pg_tables`, `has_table_privilege` for declared-powerless roles)
   and `class_exists`/`method_exists` for every declared service guard.

If the constitution and the code disagree by one character, the suite is red and merge is blocked.

---

## Stack

- **PHP 8.4 / Laravel 13**, strict types everywhere, PSR-12 via Pint
- **PostgreSQL 17** — money as `bigint` minor units; floats never touch money;
  API money values are decimal strings
- **Redis** — idempotency fast path; every write is durable-idempotent regardless
- **Ed25519 via libsodium** — attestations, event-chain signatures, offline vouchers
- **spatie/laravel-data** — typed, readonly domain data objects
- **Larastan Level 10, no baseline** — static analysis blocks merge
- Personhood behind the `GasIdentityProvider` adapter — Meridian never stores identity

## Layout

```
app/Domain/Meridian/   # system of record: ledger, issuance/PoVC, disputes, policy,
                       # offline vouchers, reserve attestation   (LicenseRef-MVL-2.0)
app/Domain/Aevum/      # system of engagement: currency fabric, Tier-0, membrane
                       #                                          (LicenseRef-AVL-2.0)
app/Domain/Joint/      # the boundary: event contract, crisis, erasure, constitution,
                       # maturity ledger, trade-off register      (both licenses)
app/Http/Controllers/Api/V1/   # the read-only public surface
docs/                  # the authoritative specification (Documents 0.1–10.7, licenses)
tests/Invariants/      # property tests — the suite carries the concurrency-safety burden
tests/Feature/         # API surface and OpenAPI document tests
```

## The public API (`/api/v1`)

Read-only by design. The posting paths exist and are invariant-gated in the domain
services, but their HTTP faces require the GAS/Sanctum/Passport auth stack, which is
InDevelopment — exposing them unauthenticated would present an unavailable capability
as available (AVL-2.0 §A-§C.13).

| Endpoint | Purpose |
|---|---|
| `GET /api/v1/maturity` | The binding maturity check (DOCUMENT 3.4) |
| `GET /api/v1/trade-off-register` | Every design tension with its honest cost |
| `GET /api/v1/currencies` | Currency registry with Core-Riba flags (read public; write governance-gated) |
| `GET /api/v1/transparency-log` | The hash-chained, Ed25519-signed event log — discrepancies surfaced, never auto-corrected |
| `GET /api/v1/incidents` | The incident disclosure clock — public promises, publicly checkable |
| `GET /api/v1/openapi.json` | The OpenAPI 3.1 document — single source of truth; maturity labels read live from the ledger |

The OpenAPI document also declares the **absent** write surface in `x-absent-operations`,
with reasons, instead of silently omitting it.

## Build & run

```bash
# Prerequisites: PHP 8.4 (pcntl, posix, sodium, pgsql), PostgreSQL 17, Redis, Composer
composer install
cp .env.example .env && php artisan key:generate

# Databases (the suite uses meridian_test via phpunit.xml)
createdb meridian && createdb meridian_test
php artisan migrate                                 # applies triggers, constraints, roles
php artisan migrate --database=pgsql_test 2>/dev/null || DB_DATABASE=meridian_test php artisan migrate

php artisan serve                                   # then GET /api/v1/maturity
```

## Test & quality gates

```bash
./vendor/bin/pest                     # full suite (invariants + feature + architecture)
./vendor/bin/pest tests/Invariants    # the constitutional core, incl. the prose-logic gate
./vendor/bin/pint --test              # PSR-12
./vendor/bin/phpstan analyse          # Larastan level 10, no baseline
composer audit                        # dependency advisories
```

The invariant suite includes fork-based concurrency stress tests (`pcntl_fork`), idempotency
races, and replay-rejection tests — per DOCUMENT 9.2, the suite carries the concurrency-safety
burden the formal proofs do not.

## The book

This repository grew from *How to 10x GDP in One Year* (كيف نضاعف الناتج المحلي الإجمالي
عشرة أضعاف في عام واحد). The complete constitutional prose — whitepapers, monetary
mathematics, governance constitution, red-team dossiers, runbooks, and the license texts —
lives in [`docs/`](docs/) and is the authority whenever prose and code could disagree
(the prose-logic gate exists to make sure they never do).

> *“This book is not a manual for seizing the economy. It is a constitution for a race in
> which humanity wins by rising, and loses by descending. Read it wisely, then begin the race.”*
