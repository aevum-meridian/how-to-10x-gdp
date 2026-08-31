<?php

/**
 * SPDX-License-Identifier: LicenseRef-MVL-2.0 AND LicenseRef-AVL-2.0
 *
 * ConcurrencyStressTest — DOCUMENT 9.2 §2 (DEV-9).
 *
 * "High parallel load against overlapping state never violates I1–I3 or
 * produces stale balances."
 *
 * DOCUMENT 9.2 §4 names WHY this suite exists: "the concurrency tests are
 * essential precisely because the formal models abstract concurrency away
 * (5.1 §5), so the suite carries the concurrency-safety burden the proofs
 * do not." Real processes, real PostgreSQL row locks, real COMMITs — the
 * balance_after chain is serialized by FOR UPDATE on the account row
 * (DOCUMENT 4.1 concurrency model), and these tests prove that lock holds
 * under fire.
 *
 * © Maher
 */

declare(strict_types=1);

use App\Domain\Meridian\Issuance\Models\Attestation;
use App\Domain\Meridian\Issuance\Models\Verifier;
use App\Domain\Meridian\Issuance\Services\IssuanceService;
use App\Domain\Meridian\Ledger\Enums\ReversalReason;
use App\Domain\Meridian\Ledger\Models\Account;
use App\Domain\Meridian\Ledger\Models\LedgerTransaction;
use App\Domain\Meridian\Ledger\Services\LedgerService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\LedgerFixtures;

/**
 * Fork $count workers, run $work in each (fresh DB connection), wait for
 * all, reconnect the parent. Returns the number of workers that exited
 * non-zero (crashed — NOT merely had transactions rejected).
 *
 * @param callable(int): void $work receives the worker index
 */
function runForkedWorkers(int $count, callable $work): int
{
    DB::disconnect();
    $pids = [];

    for ($w = 0; $w < $count; $w++) {
        $pid = pcntl_fork();

        if ($pid === 0) {
            try {
                DB::reconnect();
                $work($w);
                exit(0);
            } catch (Throwable) {
                exit(1);
            }
        }

        $pids[] = $pid;
    }

    $crashed = 0;

    foreach ($pids as $pid) {
        pcntl_waitpid($pid, $status);

        if (pcntl_wexitstatus($status) !== 0) {
            $crashed++;
        }
    }

    DB::reconnect();

    return $crashed;
}

/** Assert I1 + I2 + I3 hold for a currency, from raw history. */
function assertLedgerInvariantsHold(string $currencyId): void
{
    // I1: every posted transaction sums to zero for this currency.
    $unbalanced = DB::table('entries')
        ->select('transaction_id')
        ->where('currency_id', $currencyId)
        ->groupBy('transaction_id')
        ->havingRaw('SUM(amount) <> 0')
        ->count();
    expect($unbalanced)->toBe(0);

    // I2: every stored balance equals the recomputed entry history —
    // no stale balance survives the storm.
    $ledger = new LedgerService();
    $accounts = Account::query()->where('currency_id', $currencyId)->get();

    foreach ($accounts as $account) {
        expect($ledger->reconcile($account)->consistent)->toBeTrue(
            "Account {$account->id} balance is stale after concurrent load."
        );
    }

    // I3: the whole equals the sum of its parts — total across user and
    // system accounts is exactly zero (issuance negative mirrors supply).
    $total = (int) DB::table('accounts')->where('currency_id', $currencyId)->sum('balance_minor');
    expect($total)->toBe(0);
}

describe('ConcurrencyStressTest (DOCUMENT 9.2 §2)', function (): void {
    test('a mixed storm of mints, transfers, and reversals across 10 workers never violates I1-I3', function (): void {
        $currency = LedgerFixtures::currency();
        $accounts = [];

        for ($i = 0; $i < 6; $i++) {
            $account = LedgerFixtures::personalAccount($currency);
            LedgerFixtures::mint($account, 200_000);
            $accounts[] = $account->id;
        }

        $currencyId = $currency->id;

        $crashed = runForkedWorkers(10, function (int $w) use ($accounts, $currencyId): void {
            $ledger = new LedgerService();

            for ($i = 0; $i < 12; $i++) {
                try {
                    $roll = ($w + $i) % 3;

                    if ($roll === 0) {
                        // Mint into a random account.
                        $to = Account::query()->findOrFail($accounts[random_int(0, 5)]);
                        LedgerFixtures::mint($to, random_int(10, 500), $ledger);
                    } elseif ($roll === 1) {
                        // Transfer between two random distinct accounts.
                        $fromIdx = random_int(0, 5);
                        $from = Account::query()->findOrFail($accounts[$fromIdx]);
                        $to = Account::query()->findOrFail($accounts[($fromIdx + random_int(1, 5)) % 6]);
                        LedgerFixtures::transfer($from, $to, random_int(1, 200), $ledger);
                    } else {
                        // Transfer, then immediately reverse it with consent.
                        $fromIdx = random_int(0, 5);
                        $from = Account::query()->findOrFail($accounts[$fromIdx]);
                        $to = Account::query()->findOrFail($accounts[($fromIdx + 1) % 6]);
                        $txn = LedgerFixtures::transfer($from, $to, random_int(1, 100), $ledger);
                        $ledger->reverse($txn, ReversalReason::ErrorCorrection, 'auth:storm-'.Str::random(6));
                    }
                } catch (Illuminate\Database\QueryException) {
                    // Lock conflicts may reject a transaction whole; a
                    // rejection is lawful — a partial post never is.
                }
            }
        });

        expect($crashed)->toBe(0);
        assertLedgerInvariantsHold($currencyId);
        expect((new LedgerService())->proveSupplyIntegrity())->toBe([]);
    });

    test('a single HOT account hammered by 12 concurrent writers keeps an exact, gapless balance_after chain', function (): void {
        $currency = LedgerFixtures::currency();
        $hot = LedgerFixtures::personalAccount($currency);
        $peers = [];

        for ($i = 0; $i < 4; $i++) {
            $peer = LedgerFixtures::personalAccount($currency);
            LedgerFixtures::mint($peer, 1_000_000);
            $peers[] = $peer->id;
        }

        $hotId = $hot->id;
        $currencyId = $currency->id;

        // 12 workers, each pushing 10 small credits INTO the hot account —
        // every entry serializes on the same account row lock.
        $crashed = runForkedWorkers(12, function (int $w) use ($hotId, $peers, $currencyId): void {
            $ledger = new LedgerService();

            for ($i = 0; $i < 10; $i++) {
                try {
                    $from = Account::query()->findOrFail($peers[($w + $i) % 4]);
                    $to = Account::query()->findOrFail($hotId);
                    LedgerFixtures::transfer($from, $to, 7, $ledger);
                } catch (Illuminate\Database\QueryException) {
                    // Rejection is lawful; partial posting is not.
                }
            }
        });

        expect($crashed)->toBe(0);

        // The chain property. NOTE: entry ids are drawn from the sequence
        // BEFORE the account row lock is acquired, so id order need not be
        // lock-acquisition order — the chain is defined by the lock, not
        // the id. With uniform +7 credits the chain is decidable exactly:
        // the balance_after values must be the gapless set {7, 14, …, 7N}.
        // A single stale read would mint a duplicate or leave a gap.
        $balancesAfter = DB::table('entries')
            ->where('account_id', $hotId)
            ->orderBy('balance_after')
            ->pluck('balance_after')
            ->map(static fn (mixed $v): int => (int) $v)
            ->all();

        $count = count($balancesAfter);
        expect($count)->toBeGreaterThan(0);

        $expectedChain = range(7, 7 * $count, 7);
        expect($balancesAfter)->toBe(
            $expectedChain,
            'The balance_after chain has a duplicate or a gap: a stale balance was read under concurrency.'
        );

        // The stored balance is the chain's final link.
        $stored = (int) DB::table('accounts')->where('id', $hotId)->value('balance_minor');
        expect($stored)->toBe(7 * $count);

        assertLedgerInvariantsHold($currencyId);
    });

    test('one attestation attacked by 8 concurrent mint attempts yields EXACTLY one mint (I4 under concurrency)', function (): void {
        $currency = LedgerFixtures::currency();
        $recipient = LedgerFixtures::personalAccount($currency);

        // Quorum of 3 independent verifiers signs one valid attestation.
        $secrets = [];
        $signatures = [];
        $attestation = new Attestation([
            'currency_id' => $currency->id,
            'recipient_account_id' => $recipient->id,
            'subject_proof' => 'zkc:commitment-'.Str::random(8),
            'amount_minor' => 10_000,
            'nonce' => 'nonce-'.Str::ulid(),
            'expires_at' => now()->addHour(),
            'attester_set' => [],
            'signatures' => [],
        ]);
        $attestation->save();

        for ($i = 0; $i < 3; $i++) {
            $pair = sodium_crypto_sign_keypair();
            $verifier = new Verifier([
                'name' => "stress-verifier-{$i}",
                'pubkey' => base64_encode(sodium_crypto_sign_publickey($pair)),
                'family_scope' => 'contribution',
                'status' => 'active',
                'rotation_group' => "stress-group-{$i}",
                'bond' => 1_000_000,
            ]);
            $verifier->save();
            $secrets[] = sodium_crypto_sign_secretkey($pair);
            $signatures[] = [
                'verifier_id' => $verifier->id,
                'signature' => base64_encode(sodium_crypto_sign_detached($attestation->signablePayload(), $secrets[$i])),
            ];
        }

        $attestation->signatures = $signatures;
        $attestation->save();

        $attestationId = $attestation->id;

        // 8 workers race to consume the SAME attestation. The nonce may be
        // consumed exactly once (trg_attestations_guard_mint) no matter how
        // many hands reach for it at the same instant.
        $crashed = runForkedWorkers(8, function () use ($attestationId): void {
            try {
                $issuance = app(IssuanceService::class);
                $fresh = Attestation::query()->findOrFail($attestationId);
                $issuance->mintContribution($fresh);
            } catch (DomainException|Illuminate\Database\QueryException) {
                // Losing the race is lawful; minting twice never is.
            }
        });

        expect($crashed)->toBe(0);

        // Exactly ONE mint happened: one consuming transaction, one credit.
        $attestation->refresh();
        expect($attestation->minted_transaction_id)->not->toBeNull();

        $mintCount = DB::table('entries')
            ->where('account_id', $recipient->id)
            ->where('amount', '>', 0)
            ->count();
        expect($mintCount)->toBe(1);

        $ledger = new LedgerService();
        expect((string) $ledger->balance($recipient->refresh())->getAmount())->toBe('100.00');

        assertLedgerInvariantsHold($currency->id);
    });

    test('concurrent reversals of one transaction produce exactly one reversal, never a double-refund', function (): void {
        $ledger = new LedgerService();
        $currency = LedgerFixtures::currency();
        $alice = LedgerFixtures::personalAccount($currency);
        $bob = LedgerFixtures::personalAccount($currency);
        LedgerFixtures::mint($alice, 50_000, $ledger);

        $transfer = LedgerFixtures::transfer($alice, $bob, 9_000, $ledger);
        $transferId = $transfer->id;

        $crashed = runForkedWorkers(6, function () use ($transferId): void {
            try {
                $childLedger = new LedgerService();
                $original = LedgerTransaction::query()->findOrFail($transferId);
                $childLedger->reverse($original, ReversalReason::ErrorCorrection, 'auth:reversal-race');
            } catch (Illuminate\Database\QueryException) {
                // Losing the race is lawful.
            }
        });

        expect($crashed)->toBe(0);

        // The reversal idempotency key is derived from the original id +
        // reason, so six concurrent attempts collapse into one reversal.
        expect(DB::table('transactions')->where('reverses_transaction_id', $transferId)->count())->toBe(1);

        // Bob was refunded ONCE — not six times.
        expect((string) $ledger->balance($bob->refresh())->getAmount())->toBe('0.00')
            ->and((string) $ledger->balance($alice->refresh())->getAmount())->toBe('500.00');

        assertLedgerInvariantsHold($currency->id);
    });
});
