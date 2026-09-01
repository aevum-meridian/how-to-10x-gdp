<?php

// SPDX-License-Identifier: LicenseRef-MVL-2.0 AND LicenseRef-AVL-2.0

declare(strict_types=1);

namespace Tests\Invariants;

use App\Domain\Meridian\Dispute\Data\ArbitrationRuling;
use App\Domain\Meridian\Dispute\Models\AttestationDispute;
use App\Domain\Meridian\Dispute\Services\DisputeService;
use App\Domain\Meridian\Erasure\Exceptions\LegalHoldException;
use App\Domain\Meridian\Erasure\Models\ErasureHold;
use App\Domain\Meridian\Erasure\Models\ErasureTombstone;
use App\Domain\Meridian\Erasure\Models\PiiEncryptionKey;
use App\Domain\Meridian\Erasure\Models\PiiRecord;
use App\Domain\Meridian\Erasure\Services\ErasureService;
use App\Domain\Meridian\Issuance\Models\Attestation;
use App\Domain\Meridian\Ledger\Models\Account;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\LedgerFixtures;
use Tests\TestCase;

/**
 * CryptoShreddingErasureTest — DOCUMENT 6.5 (Data Retention, Erasure &
 * Ledger-Tombstones), reconciling I5 (append-only) with erasure rights.
 *
 * Layers proven:
 *  - crypto-shredding destroys record AND key, leaving an immutable
 *    tombstone; the person is unrecoverable, the economic facts survive
 *  - the DB trigger independently refuses a shred without a tombstone
 *  - the legal hold blocks shredding while a dispute is open (both
 *    layers), is DISCLOSED (reason + timeline in the refusal), releases
 *    on case close, and is BOUNDED (an expired hold no longer overrides
 *    erasure even if the proceeding lingers)
 *  - tombstones are append-only at the DB
 *  - reconciliation's inputs (entries, balances, conservation) are
 *    untouched by a shred
 *  - the honest caveat (cryptographic, not physical) accompanies every
 *    erasure receipt
 */
final class CryptoShreddingErasureTest extends TestCase
{
    private ErasureService $erasure;

    protected function setUp(): void
    {
        parent::setUp();
        $this->erasure = app(ErasureService::class);
    }

    // ------------------------------------------------------------------
    // The shred itself.
    // ------------------------------------------------------------------

    public function test_crypto_shredding_destroys_record_and_key_and_leaves_an_immutable_tombstone(): void
    {
        $subject = (string) Str::ulid();
        $record = $this->erasure->storePii($subject, 'kyc-contact', [
            'legal_name' => 'Amina Q. Example',
            'email' => 'amina@example.org',
        ]);

        // While alive, the vault decrypts for legitimate use.
        $this->assertSame('Amina Q. Example', $this->erasure->readPii($record)['legal_name']);

        // And the stored ciphertext never contains the plaintext.
        $raw = (string) DB::table('pii_records')->where('id', $record->id)->value('ciphertext');
        $this->assertStringNotContainsString('Amina', $raw);
        $this->assertStringNotContainsString('example.org', (string) base64_decode($raw, true));

        $keyId = $record->key_id;
        $tombstone = $this->erasure->erase($record, 'GDPR Art. 17 request');

        // Record gone, key gone: the ciphertext (even if a backup held a
        // copy) is unrecoverable without the destroyed key.
        $this->assertSame(0, PiiRecord::query()->whereKey($record->id)->count());
        $this->assertSame(0, PiiEncryptionKey::query()->whereKey($keyId)->count());

        // The tombstone proves the fact with no path to the person.
        $this->assertSame($record->id, $tombstone->pii_record_id);
        $this->assertStringNotContainsString('Amina', $tombstone->subject_digest);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $tombstone->subject_digest);

        // The honest caveat travels with the receipt: cryptographic, not
        // physical (DOCUMENT 6.5 §6).
        $this->assertStringContainsString('cryptographic, not physical', $tombstone->reason);
        $this->assertStringContainsString('as long as the cryptography holds', ErasureService::ERASURE_CAVEAT);
    }

    public function test_the_database_refuses_a_shred_without_a_tombstone(): void
    {
        $record = $this->erasure->storePii((string) Str::ulid(), 'kyc-contact', ['n' => 'x']);

        // Bypass the service: a bare DELETE with no tombstone in place is
        // refused by the trigger itself.
        try {
            DB::table('pii_records')->where('id', $record->id)->delete();
            $this->fail('A PII record was destroyed without leaving a tombstone.');
        } catch (\Illuminate\Database\QueryException $e) {
            $this->assertStringContainsString('tombstone', $e->getMessage());
        }

        $this->assertSame(1, PiiRecord::query()->whereKey($record->id)->count());
    }

    public function test_tombstones_are_append_only_at_the_database(): void
    {
        $record = $this->erasure->storePii((string) Str::ulid(), 'kyc-contact', ['n' => 'y']);
        $tombstone = $this->erasure->erase($record, 'request');

        try {
            DB::table('erasure_tombstones')->where('id', $tombstone->id)->update(['reason' => 'rewritten']);
            $this->fail('A tombstone was rewritten.');
        } catch (\Illuminate\Database\QueryException $e) {
            $this->assertStringContainsString('immutable', $e->getMessage());
        }

        try {
            DB::table('erasure_tombstones')->where('id', $tombstone->id)->delete();
            $this->fail('A tombstone was removed.');
        } catch (\Illuminate\Database\QueryException $e) {
            $this->assertStringContainsString('immutable', $e->getMessage());
        }

        $this->assertSame(1, ErasureTombstone::query()->whereKey($tombstone->id)->count());
    }

    // ------------------------------------------------------------------
    // §3 — the legal hold: narrow, disclosed, bounded.
    // ------------------------------------------------------------------

    public function test_an_open_dispute_blocks_shredding_at_both_layers_and_the_refusal_is_disclosed(): void
    {
        [$holder, ] = $this->disputedSubject();
        $record = $this->erasure->storePii($holder->id, 'kyc-identity', ['legal_name' => 'Disputed Person']);

        $this->assertFalse($this->erasure->erasable($record));

        // Service layer: the erasure is honored but DEFERRED, and the
        // refusal carries the reason and the timeline — disclosure is
        // constitutional, not courtesy.
        try {
            $this->erasure->erase($record, 'erasure request during dispute');
            $this->fail('Evidence in an open dispute was shredded.');
        } catch (LegalHoldException $e) {
            $this->assertStringContainsString('honored but DEFERRED', $e->getMessage());
            $this->assertStringContainsString('open', $e->getMessage());
            $this->assertStringContainsString('no later than', $e->getMessage());
        }

        $hold = ErasureHold::query()->where('pii_record_id', $record->id)->firstOrFail();
        $this->assertStringContainsString('defined maximum', $hold->disclosed_reason);

        // DB layer: even WITH a tombstone pre-seeded, the trigger refuses
        // the DELETE while the dispute is open and the hold un-expired.
        DB::table('erasure_tombstones')->insert([
            'id' => (string) Str::ulid(),
            'pii_record_id' => $record->id,
            'subject_digest' => hash('sha256', $record->id),
            'reason' => 'seeded for trigger test',
        ]);

        try {
            DB::table('pii_records')->where('id', $record->id)->delete();
            $this->fail('The database shredded open-dispute evidence.');
        } catch (\Illuminate\Database\QueryException $e) {
            $this->assertStringContainsString('LEGAL HOLD', $e->getMessage());
        }

        $this->assertSame(1, PiiRecord::query()->whereKey($record->id)->count());
    }

    public function test_the_hold_releases_when_the_case_closes(): void
    {
        [$holder, $attestation] = $this->disputedSubject();
        $record = $this->erasure->storePii($holder->id, 'kyc-identity', ['legal_name' => 'Later Cleared']);

        try {
            $this->erasure->erase($record, 'first request');
            $this->fail('The hold did not bind.');
        } catch (LegalHoldException) {
            // Expected while the case is open.
        }

        // The arbitrator closes the case as VALID (no fraud).
        $disputes = app(DisputeService::class);
        $dispute = AttestationDispute::query()
            ->where('attestation_id', $attestation->id)
            ->firstOrFail();
        $disputes->arbitrate($dispute, new ArbitrationRuling(
            fraudProven: false,
            fraudulentPartyAccountId: null,
            decisionReceipt: 'Public ruling: the contribution stands.',
            arbitratorSignature: 'sig:'.Str::random(32),
        ));

        // Erasure now proceeds: hold lasts ONLY as long as the proceeding.
        $this->assertTrue($this->erasure->erasable($record));
        $tombstone = $this->erasure->erase($record, 'renewed request after close');
        $this->assertSame(0, PiiRecord::query()->whereKey($record->id)->count());
        $this->assertSame($record->id, $tombstone->pii_record_id);
    }

    public function test_the_hold_is_bounded_an_expired_hold_no_longer_overrides_erasure(): void
    {
        [$holder, ] = $this->disputedSubject();
        $record = $this->erasure->storePii($holder->id, 'kyc-identity', ['legal_name' => 'Held Too Long']);

        try {
            $this->erasure->erase($record, 'request');
            $this->fail('The hold did not bind.');
        } catch (LegalHoldException) {
            // Expected: hold recorded.
        }

        // The proceeding lingers past the defined maximum. Age the hold
        // directly (the DB trigger reads PostgreSQL's own clock).
        DB::table('erasure_holds')
            ->where('pii_record_id', $record->id)
            ->update([
                'created_at' => now()->subDays(ErasureService::HOLD_MAX_DAYS + 10),
                'hold_expires_at' => now()->subDays(10),
            ]);

        // The bounded maximum is constitutional: retention may no longer
        // override erasure, even though the dispute is STILL open.
        $this->assertTrue($this->erasure->erasable($record));
        $this->erasure->erase($record, 'request after bounded maximum');
        $this->assertSame(0, PiiRecord::query()->whereKey($record->id)->count());
    }

    // ------------------------------------------------------------------
    // §4 — reconciliation survives the shred.
    // ------------------------------------------------------------------

    public function test_the_economic_facts_survive_the_shred(): void
    {
        $currency = LedgerFixtures::currency();
        $alice = LedgerFixtures::personalAccount($currency);
        $bob = LedgerFixtures::personalAccount($currency);
        LedgerFixtures::mint($alice, 10_000);
        $txn = LedgerFixtures::transfer($alice, $bob, 4_000);

        $record = $this->erasure->storePii($alice->id, 'kyc-contact', ['legal_name' => 'Alice Example']);
        $this->erasure->erase($record, 'erasure request');

        // Every on-ledger economic fact is untouched: amounts, balances,
        // conservation. Reconciliation never needed the PII.
        $this->assertSame(6_000, $alice->refresh()->balance_minor);
        $this->assertSame(4_000, $bob->refresh()->balance_minor);
        $this->assertSame(
            0,
            (int) DB::table('entries')->where('transaction_id', $txn->id)->sum('amount'),
        );
        $conservation = (int) DB::table('entries')
            ->join('accounts', 'accounts.id', '=', 'entries.account_id')
            ->where('accounts.currency_id', $currency->id)
            ->sum('entries.amount');
        $this->assertSame(0, $conservation);

        // And the on-ledger rows contain no trace of the shredded name —
        // the opaque ULID was always the only reference.
        $entryBlob = json_encode(
            DB::table('entries')
                ->join('accounts', 'accounts.id', '=', 'entries.account_id')
                ->where('accounts.currency_id', $currency->id)
                ->get(),
            JSON_THROW_ON_ERROR,
        );
        $this->assertStringNotContainsString('Alice Example', $entryBlob);
    }

    // ------------------------------------------------------------------
    // The on-ledger/off-ledger split is disciplined from day one.
    // ------------------------------------------------------------------

    public function test_ledger_tables_carry_no_pii_columns_and_the_vault_is_the_only_mapping(): void
    {
        // Schema allowlist (DOCUMENT 6.5 §6): the immutable ledger tables
        // must never grow a personal-data column. This complements the I8
        // scan with the PII vocabulary erasure law cares about.
        $piiFragments = [
            'legal_name', 'full_name', 'first_name', 'last_name', 'email',
            'phone', 'street', 'passport', 'national_id', 'tax_id',
            'date_of_birth', 'birthdate',
        ];

        foreach (['entries', 'transactions', 'accounts', 'currencies'] as $table) {
            /** @var list<object{column_name: string}> $columns */
            $columns = DB::select(
                'SELECT column_name FROM information_schema.columns WHERE table_name = ?',
                [$table],
            );
            $this->assertNotEmpty($columns);

            foreach ($columns as $column) {
                foreach ($piiFragments as $fragment) {
                    $this->assertFalse(
                        str_contains(strtolower($column->column_name), $fragment),
                        "Ledger table {$table} carries a PII column \"{$column->column_name}\" — "
                        .'a single such migration makes erasure impossible for those records.'
                    );
                }
            }
        }

        // The vault is Meridian-side only: aevum_app has NO privilege of
        // any kind on any erasure table.
        /** @var list<object{privilege_type: string}> $grants */
        $grants = DB::select(
            "SELECT privilege_type FROM information_schema.role_table_grants
             WHERE grantee = 'aevum_app'
               AND table_name IN ('pii_records', 'pii_encryption_keys', 'erasure_tombstones', 'erasure_holds')",
        );
        $this->assertSame([], $grants);
    }

    // ------------------------------------------------------------------
    // Helpers.
    // ------------------------------------------------------------------

    /**
     * A personal account that is the recipient of a minted attestation
     * now under an OPEN dispute — the shape the legal hold protects.
     *
     * @return array{Account, Attestation}
     */
    private function disputedSubject(): array
    {
        $currency = LedgerFixtures::currency();
        $holder = LedgerFixtures::personalAccount($currency);

        $attestation = new Attestation([
            'currency_id' => $currency->id,
            'recipient_account_id' => $holder->id,
            'subject_proof' => 'zkc:'.Str::random(16),
            'amount_minor' => 5_000,
            'nonce' => 'nonce-'.Str::ulid(),
            'expires_at' => now()->addHour(),
            'quorum_met' => true,
        ]);
        $attestation->save();

        $disputes = app(DisputeService::class);
        $mint = LedgerFixtures::mint($holder, 5_000);
        $attestation->minted_transaction_id = $mint->id;
        $attestation->status = 'minted';
        $attestation->save();

        $disputes->openDispute($attestation, 'challenger-erasure', 1_000);

        return [$holder, $attestation];
    }
}
