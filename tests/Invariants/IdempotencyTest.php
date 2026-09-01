<?php

/**
 * SPDX-License-Identifier: LicenseRef-MVL-2.0 AND LicenseRef-AVL-2.0
 *
 * IdempotencyTest — DOCUMENT 9.2 §2 (DEV-9).
 *
 * "Duplicate idempotency keys return the original result with no
 * double-post (ledger and event contract)."
 *
 * Idempotency is a safety property of every posting path: a retried
 * request — a stuttering client, a replayed webhook, a crashed worker
 * restarted mid-flight — must land on the ORIGINAL transaction, byte for
 * byte, and move not one additional minor unit. Three layers carry it:
 * the Redis fast path, the durable transactions.idempotency_key unique
 * index, and the idempotency_keys table — and the concurrent race is won
 * by exactly one writer, with the loser handed the winner's result.
 *
 * © Maher
 */

declare(strict_types=1);

use App\Domain\Joint\EventContract\Enums\EventKind;
use App\Domain\Joint\EventContract\Enums\EventSource;
use App\Domain\Joint\EventContract\Models\EventSigner;
use App\Domain\Joint\EventContract\Services\EventChainService;
use App\Domain\Meridian\Ledger\Data\EntryDraft;
use App\Domain\Meridian\Ledger\Data\TransactionDraft;
use App\Domain\Meridian\Ledger\Enums\TransactionKind;
use App\Domain\Meridian\Ledger\Services\LedgerService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\LedgerFixtures;

describe('IdempotencyTest (DOCUMENT 9.2 §2)', function (): void {
    test('a duplicate ledger post returns the ORIGINAL transaction and moves nothing twice', function (): void {
        $ledger = new LedgerService();
        $currency = LedgerFixtures::currency();
        $alice = LedgerFixtures::personalAccount($currency);
        $bob = LedgerFixtures::personalAccount($currency);
        LedgerFixtures::mint($alice, 50_000, $ledger);

        $key = 'transfer:'.Str::ulid();
        $draft = fn (): TransactionDraft => new TransactionDraft(
            kind: TransactionKind::Transfer,
            entries: [
                new EntryDraft($alice->id, $currency->id, -7_500, holderAuthorizationRef: 'auth:idem-1'),
                new EntryDraft($bob->id, $currency->id, 7_500),
            ],
            idempotencyKey: $key,
        );

        $first = $ledger->post($draft());
        $replayed = $ledger->post($draft());

        // Same transaction, not a twin.
        expect($replayed->id)->toBe($first->id);

        // Exactly one posting: two entries under the key, not four.
        expect(DB::table('entries')->where('transaction_id', $first->id)->count())->toBe(2)
            ->and(DB::table('transactions')->where('idempotency_key', $key)->count())->toBe(1);

        // Balances moved exactly once.
        expect((string) $ledger->balance($alice->refresh())->getAmount())->toBe('425.00')
            ->and((string) $ledger->balance($bob->refresh())->getAmount())->toBe('75.00');
    });

    test('idempotency survives a cache wipe: the durable table path returns the original when Redis has forgotten', function (): void {
        $ledger = new LedgerService();
        $currency = LedgerFixtures::currency();
        $alice = LedgerFixtures::personalAccount($currency);
        $bob = LedgerFixtures::personalAccount($currency);
        LedgerFixtures::mint($alice, 10_000, $ledger);

        $key = 'transfer:'.Str::ulid();
        $draft = fn (): TransactionDraft => new TransactionDraft(
            kind: TransactionKind::Transfer,
            entries: [
                new EntryDraft($alice->id, $currency->id, -1_000, holderAuthorizationRef: 'auth:idem-2'),
                new EntryDraft($bob->id, $currency->id, 1_000),
            ],
            idempotencyKey: $key,
        );

        $first = $ledger->post($draft());

        // The cache dies. The ledger must not: durability cannot depend on
        // a cache — the unique index and idempotency_keys table remain.
        Cache::forget('ledger:idem:'.$key);

        $replayed = $ledger->post($draft());

        expect($replayed->id)->toBe($first->id)
            ->and(DB::table('transactions')->where('idempotency_key', $key)->count())->toBe(1)
            ->and((string) $ledger->balance($bob->refresh())->getAmount())->toBe('10.00');
    });

    test('CONCURRENT duplicates race and exactly one wins; every loser receives the winner result', function (): void {
        $currency = LedgerFixtures::currency();
        $alice = LedgerFixtures::personalAccount($currency);
        $bob = LedgerFixtures::personalAccount($currency);
        LedgerFixtures::mint($alice, 100_000);

        $key = 'transfer:'.Str::ulid();
        $aliceId = $alice->id;
        $bobId = $bob->id;
        $currencyId = $currency->id;

        // Fork 6 workers, all posting the SAME draft with the SAME key
        // simultaneously through fresh connections.
        DB::disconnect();
        $workers = [];

        for ($w = 0; $w < 6; $w++) {
            $pid = pcntl_fork();

            if ($pid === 0) {
                try {
                    DB::reconnect();
                    $childLedger = new LedgerService();

                    $childLedger->post(new TransactionDraft(
                        kind: TransactionKind::Transfer,
                        entries: [
                            new EntryDraft($aliceId, $currencyId, -2_000, holderAuthorizationRef: 'auth:idem-race'),
                            new EntryDraft($bobId, $currencyId, 2_000),
                        ],
                        idempotencyKey: $key,
                    ));

                    exit(0);
                } catch (Throwable) {
                    exit(1);
                }
            }

            $workers[] = $pid;
        }

        $failures = 0;

        foreach ($workers as $pid) {
            pcntl_waitpid($pid, $status);

            if (pcntl_wexitstatus($status) !== 0) {
                $failures++;
            }
        }

        DB::reconnect();

        // Every worker got a result — the losers were HANDED the winner's
        // transaction, not an exception.
        expect($failures)->toBe(0);

        // Exactly one posting exists under the key; value moved exactly once.
        expect(DB::table('transactions')->where('idempotency_key', $key)->count())->toBe(1);

        $ledger = new LedgerService();
        expect((string) $ledger->balance($bob->refresh())->getAmount())->toBe('20.00')
            ->and((string) $ledger->balance($alice->refresh())->getAmount())->toBe('980.00');
    });

    test('the DB unique index refuses a second transaction under the same key independently of any service', function (): void {
        $currency = LedgerFixtures::currency();
        $alice = LedgerFixtures::personalAccount($currency);
        LedgerFixtures::mint($alice, 5_000);

        $existing = DB::table('transactions')->orderByDesc('created_at')->first();
        assert($existing !== null);

        try {
            DB::table('transactions')->insert([
                'id' => (string) Str::ulid(),
                'kind' => 'transfer',
                'idempotency_key' => $existing->idempotency_key,
                'created_at' => now(),
            ]);
            $this->fail('The database accepted a second transaction under a used idempotency key.');
        } catch (Illuminate\Database\QueryException $e) {
            expect($e->getMessage())->toContain('transactions_idempotency_key_unique');
        }
    });

    test('reverse() is idempotent by construction: reversing the same transaction twice for the same reason yields ONE reversal', function (): void {
        $ledger = new LedgerService();
        $currency = LedgerFixtures::currency();
        $alice = LedgerFixtures::personalAccount($currency);
        $bob = LedgerFixtures::personalAccount($currency);
        LedgerFixtures::mint($alice, 30_000, $ledger);

        $transfer = LedgerFixtures::transfer($alice, $bob, 4_000, $ledger);

        $auth = 'auth:reversal-consent-'.Str::random(6);
        $first = $ledger->reverse($transfer, App\Domain\Meridian\Ledger\Enums\ReversalReason::ErrorCorrection, $auth);
        $second = $ledger->reverse($transfer, App\Domain\Meridian\Ledger\Enums\ReversalReason::ErrorCorrection, $auth);

        expect($second->id)->toBe($first->id)
            ->and(DB::table('transactions')->where('reverses_transaction_id', $transfer->id)->count())->toBe(1);

        // Net effect of transfer + exactly one reversal: back to start.
        expect((string) $ledger->balance($bob->refresh())->getAmount())->toBe('0.00');
    });

    test('the event contract is idempotent: a duplicate append returns the original event flagged as replayed, appending nothing', function (): void {
        $pair = sodium_crypto_sign_keypair();
        EventSigner::query()->create([
            'source' => 'aevum',
            'public_key' => base64_encode(sodium_crypto_sign_publickey($pair)),
            'status' => 'active',
            'registered_at' => now(),
        ]);
        $secret = sodium_crypto_sign_secretkey($pair);

        $chain = app(EventChainService::class);
        $key = 'evt-idem-'.Str::random(10);

        $first = $chain->append(
            EventSource::Aevum,
            EventKind::ProposalFilterVerdict,
            ['verdict' => 'pass', 'experience_id' => (string) Str::ulid()],
            $key,
            $secret,
        );
        $replay = $chain->append(
            EventSource::Aevum,
            EventKind::ProposalFilterVerdict,
            ['verdict' => 'pass', 'experience_id' => (string) Str::ulid()],
            $key,
            $secret,
        );

        expect($first->replayed)->toBeFalse()
            ->and($replay->replayed)->toBeTrue()
            ->and($replay->event->id)->toBe($first->event->id)
            ->and(DB::table('cross_system_events')->where('idempotency_key', $key)->count())->toBe(1);

        // The replay changed NOTHING about the stored event — same payload
        // hash, same chain position. The second payload never entered.
        expect($replay->event->payload_hash)->toBe($first->event->payload_hash);
    });
});
