<?php

// SPDX-License-Identifier: LicenseRef-MVL-2.0

declare(strict_types=1);

namespace Tests\Invariants;

use App\Domain\Meridian\Ledger\Enums\SystemAccountRole;
use App\Domain\Meridian\Ledger\Models\Account;
use App\Domain\Meridian\Ledger\Services\LedgerService;
use App\Domain\Meridian\Offline\Enums\VoucherStatus;
use App\Domain\Meridian\Offline\Exceptions\VoucherBoundException;
use App\Domain\Meridian\Offline\Models\OfflineVoucher;
use App\Domain\Meridian\Offline\Services\OfflineVoucherService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\LedgerFixtures;
use Tests\TestCase;

/**
 * OfflineVoucherTest — DOCUMENT 6.3.
 *
 * Reaching the deviceless without betraying the ledger: the reservation
 * is a REAL balanced ledger transaction (I1 holds through the offline
 * window); the per-voucher double-spend bound = the reserved amount,
 * enforced at the service AND the DB CHECK; deferred records are
 * holder-signed and nonce-replay-bounded; expiry returns the remainder;
 * the custodial tier exists only behind an acknowledged disclosure.
 */
final class OfflineVoucherTest extends TestCase
{
    private OfflineVoucherService $service;

    private LedgerService $ledger;

    /** @var non-empty-string */
    private string $holderSecret;

    private string $holderPublicBase64;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ledger = new LedgerService();
        $this->service = new OfflineVoucherService($this->ledger);

        $pair = sodium_crypto_sign_keypair();
        $secret = sodium_crypto_sign_secretkey($pair);
        assert($secret !== '');
        $this->holderSecret = $secret;
        $this->holderPublicBase64 = base64_encode(sodium_crypto_sign_publickey($pair));
    }

    /** @return array{holder: Account, payee: Account} */
    private function fundedAccounts(int $balanceMinor = 10_000): array
    {
        $currency = LedgerFixtures::currency();
        $holder = LedgerFixtures::personalAccount($currency);
        $payee = LedgerFixtures::personalAccount($currency);
        LedgerFixtures::mint($holder, $balanceMinor, $this->ledger);

        return ['holder' => $holder->refresh(), 'payee' => $payee];
    }

    private function sign(string $voucherId, string $payeeId, int $amount, string $nonce): string
    {
        return base64_encode(sodium_crypto_sign_detached(
            OfflineVoucherService::deferredMessage($voucherId, $payeeId, $amount, $nonce),
            $this->holderSecret,
        ));
    }

    public function test_a_reservation_is_a_real_balanced_holder_authorized_transaction(): void
    {
        ['holder' => $holder] = $this->fundedAccounts();

        $voucher = $this->service->reserve(
            $holder,
            4_000,
            $this->holderPublicBase64,
            holderAuthorizationRef: 'auth:'.Str::ulid(),
        );

        // The holder's online balance dropped by the reservation: the
        // reserved amount cannot be double-spent online while reserved.
        $this->assertSame(6_000, (int) $holder->refresh()->balance_minor);

        // Conservation (I1): the reservation moved value, created none.
        $sum = DB::selectOne(
            'SELECT COALESCE(SUM(amount), 0) AS total FROM entries WHERE transaction_id = ?',
            [$voucher->reservation_transaction_id],
        );
        $this->assertNotNull($sum);
        $this->assertSame(0, (int) $sum->total);

        // Attempting to reserve beyond the balance is refused.
        try {
            $this->service->reserve($holder->refresh(), 999_999, $this->holderPublicBase64, 'auth:'.Str::ulid());
            $this->fail('An unfunded reservation must be refused.');
        } catch (VoucherBoundException $e) {
            $this->assertStringContainsString('fully funded', $e->getMessage());
        }
    }

    public function test_the_double_spend_bound_holds_at_service_and_db_layers(): void
    {
        ['holder' => $holder, 'payee' => $payee] = $this->fundedAccounts();

        $voucher = $this->service->reserve($holder, 3_000, $this->holderPublicBase64, 'auth:'.Str::ulid());

        // Spend up to the bound in pieces.
        $this->service->settleDeferred($voucher->refresh(), $payee, 1_800, 'n1', $this->sign($voucher->id, $payee->id, 1_800, 'n1'));
        $this->service->settleDeferred($voucher->refresh(), $payee, 1_200, 'n2', $this->sign($voucher->id, $payee->id, 1_200, 'n2'));

        $this->assertSame(3_000, (int) $payee->refresh()->balance_minor);
        $this->assertSame(0, $voucher->refresh()->remainingMinor());

        // One unit over the bound: service refuses.
        try {
            $this->service->settleDeferred($voucher->refresh(), $payee, 1, 'n3', $this->sign($voucher->id, $payee->id, 1, 'n3'));
            $this->fail('Settlement beyond the bound must be refused.');
        } catch (VoucherBoundException $e) {
            $this->assertStringContainsString('remaining bound', $e->getMessage());
        }

        // DB layer independently refuses settled > reserved.
        try {
            DB::table('offline_vouchers')->where('id', $voucher->id)->update([
                'settled_amount_minor' => 3_001,
            ]);
            $this->fail('The DB CHECK must refuse settled > reserved.');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }

        // And the reservation itself is immutable — the bound cannot be
        // quietly widened after issue.
        try {
            DB::table('offline_vouchers')->where('id', $voucher->id)->update([
                'reserved_amount_minor' => 999_999,
            ]);
            $this->fail('Widening the bound must be refused.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('immutable', $e->getMessage());
        }
    }

    public function test_replay_and_forgery_are_refused(): void
    {
        ['holder' => $holder, 'payee' => $payee] = $this->fundedAccounts();
        $voucher = $this->service->reserve($holder, 5_000, $this->holderPublicBase64, 'auth:'.Str::ulid());

        $signature = $this->sign($voucher->id, $payee->id, 1_000, 'nonce-a');
        $this->service->settleDeferred($voucher->refresh(), $payee, 1_000, 'nonce-a', $signature);

        // Replaying the intercepted record: refused by the nonce bound.
        try {
            $this->service->settleDeferred($voucher->refresh(), $payee, 1_000, 'nonce-a', $signature);
            $this->fail('Nonce replay must be refused.');
        } catch (VoucherBoundException $e) {
            $this->assertStringContainsString('replay is refused', $e->getMessage());
        }

        $this->assertSame(1_000, (int) $payee->refresh()->balance_minor);

        // A record signed by someone other than the holder: refused.
        $thief = sodium_crypto_sign_keypair();
        $forged = base64_encode(sodium_crypto_sign_detached(
            OfflineVoucherService::deferredMessage($voucher->id, $payee->id, 2_000, 'nonce-b'),
            sodium_crypto_sign_secretkey($thief),
        ));

        try {
            $this->service->settleDeferred($voucher->refresh(), $payee, 2_000, 'nonce-b', $forged);
            $this->fail('A forged signature must be refused.');
        } catch (VoucherBoundException $e) {
            $this->assertStringContainsString('does not verify', $e->getMessage());
        }

        // A redirected record (signed for a different payee): refused.
        $other = LedgerFixtures::personalAccount($holder->currency()->firstOrFail());
        $redirect = $this->sign($voucher->id, $other->id, 500, 'nonce-c');

        try {
            $this->service->settleDeferred($voucher->refresh(), $payee, 500, 'nonce-c', $redirect);
            $this->fail('A redirected record must be refused.');
        } catch (VoucherBoundException $e) {
            $this->assertStringContainsString('does not verify', $e->getMessage());
        }
    }

    public function test_expiry_returns_the_unspent_remainder_and_conserves(): void
    {
        ['holder' => $holder, 'payee' => $payee] = $this->fundedAccounts();
        $voucher = $this->service->reserve($holder, 4_000, $this->holderPublicBase64, 'auth:'.Str::ulid());

        $this->service->settleDeferred($voucher->refresh(), $payee, 1_500, 'n1', $this->sign($voucher->id, $payee->id, 1_500, 'n1'));

        // Early forced expiry is refused.
        try {
            $this->service->expire($voucher->refresh());
            $this->fail('Early expiry must be refused.');
        } catch (\DomainException $e) {
            $this->assertStringContainsString('cannot be forced early', $e->getMessage());
        }

        // Age the voucher at the DB layer (the window is a data fact).
        DB::table('offline_vouchers')->where('id', $voucher->id)->update(['expires_at' => now()->subDay()]);

        $expired = $this->service->expire($voucher->refresh());
        $this->assertSame(VoucherStatus::Expired, $expired->status);

        // The remainder (4000 - 1500) returned to the holder:
        // 10000 - 4000 + 2500 = 8500.
        $this->assertSame(8_500, (int) $holder->refresh()->balance_minor);
        $this->assertSame(1_500, (int) $payee->refresh()->balance_minor);

        // Conservation across the whole lifecycle: holder + payee +
        // reservation account = the original mint.
        $currency = $holder->currency()->firstOrFail();
        $reservation = Account::query()
            ->where('currency_id', $currency->id)
            ->where('system_role', SystemAccountRole::Reservation->value)
            ->firstOrFail();
        $this->assertSame(0, (int) $reservation->refresh()->balance_minor);
        $this->assertSame(
            10_000,
            (int) $holder->refresh()->balance_minor + (int) $payee->refresh()->balance_minor,
        );

        // An expired voucher accepts nothing further — service and DB.
        try {
            $this->service->settleDeferred($expired, $payee, 100, 'n9', $this->sign($voucher->id, $payee->id, 100, 'n9'));
            $this->fail('An expired voucher must accept no settlement.');
        } catch (VoucherBoundException $e) {
            $this->assertStringContainsString('no further settlement', $e->getMessage());
        }

        try {
            DB::table('offline_vouchers')->where('id', $voucher->id)->update([
                'settled_amount_minor' => 2_000,
            ]);
            $this->fail('The DB trigger must refuse settlement on a non-reserved voucher.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('accepts no further settlement', $e->getMessage());
        }
    }

    public function test_randomized_offline_storms_never_exceed_the_bound_or_break_conservation(): void
    {
        mt_srand(20260815);
        ['holder' => $holder, 'payee' => $payee] = $this->fundedAccounts(20_000);
        $reserved = 8_000;
        $voucher = $this->service->reserve($holder, $reserved, $this->holderPublicBase64, 'auth:'.Str::ulid());

        $settled = 0;
        $refused = 0;

        for ($i = 0; $i < 30; $i++) {
            $amount = mt_rand(200, 1_500);
            $nonce = "storm-{$i}";

            // 25% of records are adversarial: replays of nonce 0 or forgeries.
            $adversarial = mt_rand(1, 100) <= 25 && $i > 0;

            try {
                if ($adversarial) {
                    // Replay the first nonce with a fresh valid signature —
                    // still must be refused by the nonce bound.
                    $this->service->settleDeferred(
                        $voucher->refresh(),
                        $payee,
                        $amount,
                        'storm-0',
                        $this->sign($voucher->id, $payee->id, $amount, 'storm-0'),
                    );
                    $this->fail('An adversarial replay must never settle.');
                }

                $this->service->settleDeferred(
                    $voucher->refresh(),
                    $payee,
                    $amount,
                    $nonce,
                    $this->sign($voucher->id, $payee->id, $amount, $nonce),
                );
                $settled += $amount;
            } catch (VoucherBoundException) {
                $refused++;
            }

            // Invariants after every step.
            $fresh = $voucher->refresh();
            $this->assertLessThanOrEqual($reserved, $fresh->settled_amount_minor);
            $this->assertSame($settled, $fresh->settled_amount_minor);
            $this->assertSame($settled, (int) $payee->refresh()->balance_minor);
        }

        $this->assertGreaterThan(0, $refused);

        // Total exposure across the storm never exceeded the reservation.
        $this->assertLessThanOrEqual($reserved, $settled);

        // Conservation: nothing was created or destroyed offline.
        $this->assertSame(
            20_000,
            (int) $holder->refresh()->balance_minor
            + (int) $payee->refresh()->balance_minor
            + $voucher->refresh()->remainingMinor(),
        );
    }

    public function test_the_custodial_tier_requires_acknowledged_disclosure(): void
    {
        ['holder' => $holder] = $this->fundedAccounts();

        // Service layer: custodial without acknowledgment is refused.
        try {
            $this->service->reserve(
                $holder,
                1_000,
                $this->holderPublicBase64,
                'auth:'.Str::ulid(),
                custodialTier: true,
                custodialDisclosureAcknowledged: false,
            );
            $this->fail('The custodial tier without disclosure must be refused.');
        } catch (\DomainException $e) {
            $this->assertStringContainsString('informed trade', $e->getMessage());
        }

        // DB layer: the consent CHECK refuses the same, independently.
        try {
            DB::table('offline_vouchers')->insert([
                'id' => strtolower((string) Str::ulid()),
                'account_id' => $holder->id,
                'currency_id' => $holder->currency_id,
                'reserved_amount_minor' => 1_000,
                'settled_amount_minor' => 0,
                'reservation_transaction_id' => 'txn:forged',
                'holder_public_key' => $this->holderPublicBase64,
                'status' => 'reserved',
                'expires_at' => now()->addDays(30),
                'custodial_tier' => true,
                'custodial_disclosure_acknowledged' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->fail('The DB CHECK must refuse custodial without acknowledgment.');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }

        // With informed consent, the custodial tier stands.
        $voucher = $this->service->reserve(
            $holder->refresh(),
            1_000,
            $this->holderPublicBase64,
            'auth:'.Str::ulid(),
            custodialTier: true,
            custodialDisclosureAcknowledged: true,
        );
        $this->assertTrue($voucher->custodial_tier);
        $this->assertTrue($voucher->custodial_disclosure_acknowledged);
        $this->assertSame(0, OfflineVoucher::query()->where('custodial_tier', true)->where('custodial_disclosure_acknowledged', false)->count());
    }
}
