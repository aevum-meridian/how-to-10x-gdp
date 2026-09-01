<?php

// SPDX-License-Identifier: LicenseRef-MVL-2.0 AND LicenseRef-AVL-2.0

declare(strict_types=1);

namespace Tests\Invariants;

use App\Domain\Joint\EventContract\Enums\EventKind;
use App\Domain\Joint\EventContract\Enums\EventSource;
use App\Domain\Joint\EventContract\Enums\EventStatus;
use App\Domain\Joint\EventContract\Models\CrossSystemEvent;
use App\Domain\Joint\EventContract\Models\EventSigner;
use App\Domain\Joint\EventContract\Services\EventChainService;
use App\Domain\Meridian\Ingress\Services\ProposalIngressService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\LedgerFixtures;
use Tests\TestCase;

/**
 * EventChainIntegrityTest — DEV-7.1 (DOCUMENT 7.1).
 *
 * The signed, hash-chained, idempotent stream: replays are no-ops,
 * tampering is detectable (service walk AND DB trigger), forgery is
 * refused, and the validating ingress turns a compromised Aevum into
 * rejected proposals — never an invalid Meridian entry.
 */
final class EventChainIntegrityTest extends TestCase
{
    private EventChainService $chain;

    /** @var non-empty-string */
    private string $aevumSecret;

    /** @var non-empty-string */
    private string $meridianSecret;

    protected function setUp(): void
    {
        parent::setUp();
        $this->chain = app(EventChainService::class);

        $aevumPair = sodium_crypto_sign_keypair();
        $meridianPair = sodium_crypto_sign_keypair();
        $this->aevumSecret = sodium_crypto_sign_secretkey($aevumPair);
        $this->meridianSecret = sodium_crypto_sign_secretkey($meridianPair);

        foreach ([
            ['aevum', $aevumPair],
            ['meridian', $meridianPair],
        ] as [$source, $pair]) {
            EventSigner::query()->create([
                'source' => $source,
                'public_key' => base64_encode(sodium_crypto_sign_publickey($pair)),
                'status' => 'active',
                'registered_at' => now(),
            ]);
        }
    }

    /** @param array<string, mixed> $payload */
    private function emit(array $payload = ['n' => 1], ?string $key = null): CrossSystemEvent
    {
        return $this->chain->append(
            source: EventSource::Aevum,
            kind: EventKind::ProposalFilterVerdict,
            payload: $payload,
            idempotencyKey: $key ?? 'evt-'.Str::random(16),
            secretKey: $this->aevumSecret,
        )->event;
    }

    public function test_the_chain_links_and_verifies_end_to_end(): void
    {
        $first = $this->emit(['n' => 1]);
        $second = $this->emit(['n' => 2]);
        $third = $this->emit(['n' => 3]);

        $this->assertSame(EventChainService::GENESIS_HASH, $first->prev_hash);
        $this->assertSame($first->entry_hash, $second->prev_hash);
        $this->assertSame($second->entry_hash, $third->prev_hash);

        $verification = $this->chain->verifyChain();
        $this->assertTrue($verification->intact, json_encode($verification->defects, JSON_THROW_ON_ERROR));
        $this->assertSame(3, $verification->eventsVerified);
    }

    public function test_a_replayed_idempotency_key_is_a_no_op_returning_the_original(): void
    {
        $key = 'replay-'.Str::random(12);
        $original = $this->chain->append(
            EventSource::Aevum,
            EventKind::ProposalFilterVerdict,
            ['v' => 'first'],
            $key,
            $this->aevumSecret,
        );
        $replay = $this->chain->append(
            EventSource::Aevum,
            EventKind::ProposalFilterVerdict,
            ['v' => 'second — must be ignored'],
            $key,
            $this->aevumSecret,
        );

        $this->assertFalse($original->replayed);
        $this->assertTrue($replay->replayed);
        $this->assertSame($original->event->id, $replay->event->id);
        $this->assertSame(['v' => 'first'], $replay->event->payload);
        $this->assertSame(1, CrossSystemEvent::query()->where('idempotency_key', $key)->count());
    }

    public function test_tampering_with_a_stored_event_is_rejected_by_the_db_and_detected_by_the_walk(): void
    {
        $event = $this->emit(['amount' => 100]);
        $this->emit(['n' => 'successor']);

        // DB layer: the chained content is immutable.
        try {
            DB::table('cross_system_events')->where('id', $event->id)
                ->update(['canonical_payload' => '{"amount":999999}']);
            $this->fail('EVENT CHAIN violated: chained content was rewritten.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('immutable', $e->getMessage());
        }

        // Even a trigger-bypassing tamper (session_replication_role,
        // the superuser's cheat) is DETECTED by the verification walk.
        DB::unprepared('SET session_replication_role = replica');
        try {
            DB::table('cross_system_events')->where('id', $event->id)
                ->update(['canonical_payload' => '{"amount":999999}']);
        } finally {
            DB::unprepared('SET session_replication_role = DEFAULT');
        }

        $verification = $this->chain->verifyChain();
        $this->assertFalse($verification->intact);
        $this->assertNotEmpty($verification->defects);
        $defectText = json_encode($verification->defects, JSON_THROW_ON_ERROR);
        $this->assertStringContainsString('tampered', $defectText);
    }

    public function test_a_spliced_or_wrongly_hashed_insert_is_rejected_at_the_door(): void
    {
        $this->emit(['n' => 1]);

        $insert = static fn (string $prevHash, string $entryHash): bool => DB::table('cross_system_events')->insert([
            'id' => strtolower((string) Str::ulid()),
            'source' => 'aevum',
            'kind' => 'proposal.filter_verdict',
            'payload' => '{"n":2}',
            'canonical_payload' => '{"n":2}',
            'prev_hash' => $prevHash,
            'entry_hash' => $entryHash,
            'signature' => base64_encode(str_repeat('x', 64)),
            'idempotency_key' => 'splice-'.Str::random(8),
            'status' => 'emitted',
            'created_at' => now(),
        ]);

        // Splice: prev_hash pointing at genesis when the chain has a head.
        try {
            $insert(EventChainService::GENESIS_HASH, str_repeat('a', 64));
            $this->fail('EVENT CHAIN violated: a spliced link was accepted.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('does not link', $e->getMessage());
        }

        // Correct link but a hash that does not recompute from content.
        /** @var object{entry_hash: string} $head */
        $head = DB::selectOne('SELECT entry_hash FROM cross_system_events ORDER BY seq DESC LIMIT 1');
        try {
            $insert($head->entry_hash, str_repeat('b', 64));
            $this->fail('EVENT CHAIN violated: a mis-hashed event was accepted.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('does not recompute', $e->getMessage());
        }
    }

    public function test_a_forged_signature_is_detected_and_refused_before_processing(): void
    {
        // Sign with a key that is NOT the registered Aevum key.
        $rogue = sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair());
        $forged = $this->chain->append(
            EventSource::Aevum,
            EventKind::ProposalTransfer,
            ['from_account_id' => 'x', 'to_account_id' => 'y', 'currency_id' => 'z', 'amount_minor' => 1],
            'forged-'.Str::random(8),
            $rogue,
        )->event;

        // The walk flags it…
        $verification = $this->chain->verifyChain();
        $this->assertFalse($verification->intact);
        $this->assertStringContainsString(
            'signature does not verify',
            json_encode($verification->defects, JSON_THROW_ON_ERROR),
        );

        // …and the ingress refuses it before validation even begins.
        $ingress = app(ProposalIngressService::class);
        $this->expectException(\App\Domain\Joint\EventContract\Exceptions\ChainIntegrityException::class);
        $ingress->receiveProposal($forged, $this->meridianSecret);
    }

    public function test_the_ingress_validates_never_trusts_a_compromised_aevum_yields_rejections_not_entries(): void
    {
        $currency = LedgerFixtures::currency();
        $alice = LedgerFixtures::personalAccount($currency);
        $bob = LedgerFixtures::personalAccount($currency);
        LedgerFixtures::mint($alice, 10_000);

        $entriesBefore = (int) DB::table('entries')->count();
        $ingress = app(ProposalIngressService::class);

        // A perfectly signed, perfectly chained proposal to debit Alice
        // WITHOUT her authorization: valid message, invalid economics.
        // I10 rejects it — a compromised Aevum cannot spend for her.
        $unauthorized = $this->chain->append(
            EventSource::Aevum,
            EventKind::ProposalTransfer,
            [
                'from_account_id' => $alice->id,
                'to_account_id' => $bob->id,
                'currency_id' => $currency->id,
                'amount_minor' => 5_000,
            ],
            'prop-'.Str::random(12),
            $this->aevumSecret,
        )->event;

        $outcome = $ingress->receiveProposal($unauthorized, $this->meridianSecret);
        $this->assertFalse($outcome->committed);
        $this->assertNotNull($outcome->rejectionReason);
        $this->assertStringContainsString('I10', (string) $outcome->rejectionReason);
        $this->assertSame(EventKind::ConfirmationRejected, $outcome->confirmation->kind);
        $this->assertSame(
            EventStatus::Rejected,
            CrossSystemEvent::query()->findOrFail($unauthorized->id)->status,
        );

        // An overdraft attempt with authorization: rejected too.
        $overdraft = $this->chain->append(
            EventSource::Aevum,
            EventKind::ProposalTransfer,
            [
                'from_account_id' => $alice->id,
                'to_account_id' => $bob->id,
                'currency_id' => $currency->id,
                'amount_minor' => 999_999,
                'holder_authorization_ref' => 'auth:alice:overdraft',
            ],
            'prop-'.Str::random(12),
            $this->aevumSecret,
        )->event;

        $outcome = $ingress->receiveProposal($overdraft, $this->meridianSecret);
        $this->assertFalse($outcome->committed);

        // Garbage payloads: rejected with reasons, never exceptions
        // escaping as entries.
        $garbage = $this->chain->append(
            EventSource::Aevum,
            EventKind::ProposalTransfer,
            ['from_account_id' => '', 'amount_minor' => -5],
            'prop-'.Str::random(12),
            $this->aevumSecret,
        )->event;

        $outcome = $ingress->receiveProposal($garbage, $this->meridianSecret);
        $this->assertFalse($outcome->committed);

        // Across ALL of the above: not one ledger entry appeared.
        $this->assertSame(
            $entriesBefore,
            (int) DB::table('entries')->count(),
            'FAILURE ISOLATION violated: a rejected proposal produced a ledger entry.'
        );
    }

    public function test_a_valid_proposal_commits_once_and_replay_returns_the_original_outcome(): void
    {
        $currency = LedgerFixtures::currency();
        $alice = LedgerFixtures::personalAccount($currency);
        $bob = LedgerFixtures::personalAccount($currency);
        LedgerFixtures::mint($alice, 10_000);

        $ingress = app(ProposalIngressService::class);

        $proposal = $this->chain->append(
            EventSource::Aevum,
            EventKind::ProposalTransfer,
            [
                'from_account_id' => $alice->id,
                'to_account_id' => $bob->id,
                'currency_id' => $currency->id,
                'amount_minor' => 2_500,
                'holder_authorization_ref' => 'auth:alice:'.Str::random(6),
            ],
            'prop-'.Str::random(12),
            $this->aevumSecret,
        )->event;

        $first = $ingress->receiveProposal($proposal, $this->meridianSecret);
        $this->assertTrue($first->committed);
        $this->assertNotNull($first->transactionId);
        $this->assertSame(EventKind::ConfirmationCommitted, $first->confirmation->kind);

        $entriesAfterFirst = (int) DB::table('entries')->count();

        // Replay the SAME proposal: no double-commit, original outcome.
        $second = $ingress->receiveProposal($proposal, $this->meridianSecret);
        $this->assertTrue($second->committed);
        $this->assertSame($first->transactionId, $second->transactionId);
        $this->assertSame($first->confirmation->id, $second->confirmation->id);
        $this->assertSame($entriesAfterFirst, (int) DB::table('entries')->count());

        // The whole stream — proposal + confirmation — verifies intact.
        $verification = $this->chain->verifyChain();
        $this->assertTrue($verification->intact, json_encode($verification->defects, JSON_THROW_ON_ERROR));
    }

    public function test_a_terminal_outcome_cannot_be_rewritten(): void
    {
        $event = $this->emit();
        DB::table('cross_system_events')->where('id', $event->id)->update([
            'status' => 'rejected', 'rejection_reason' => 'test terminal',
        ]);

        try {
            DB::table('cross_system_events')->where('id', $event->id)->update([
                'status' => 'committed', 'result_transaction_id' => 'fabricated',
            ]);
            $this->fail('EVENT CHAIN violated: a rejection was rewritten into a commit.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('terminal outcome', $e->getMessage());
        }
    }
}
