<?php

/**
 * SPDX-License-Identifier: LicenseRef-MVL-2.0
 *
 * DEV-6.x / DOCUMENT 6.3 — reaching the deviceless without betraying
 * the ledger.
 *
 * The voucher lifecycle: reserve() posts a REAL balanced reservation to
 * Meridian (holder → reservation system account, holder-authorized, so
 * conservation I1 and consent I10 hold throughout — the reserved amount
 * cannot be double-spent online while reserved). Offline transfers are
 * holder-SIGNED deferred records; settleDeferred() validates the
 * Ed25519 signature and settles against the reservation WITHIN the
 * per-voucher bound. expire() returns the unspent remainder.
 *
 * Honesty, stated in code as in prose (DOCUMENT 6.3 §3/§6): offline is
 * where the body's guarantees are WEAKEST by physical necessity — the
 * design bounds double-spend to the reserved amount rather than
 * pretending to eliminate it, and the custodial tier is available only
 * behind an explicit acknowledged disclosure (a DB CHECK, not a UI
 * promise). © Maher
 */

declare(strict_types=1);

namespace App\Domain\Meridian\Offline\Services;

use App\Domain\Meridian\Ledger\Data\EntryDraft;
use App\Domain\Meridian\Ledger\Data\TransactionDraft;
use App\Domain\Meridian\Ledger\Enums\SystemAccountRole;
use App\Domain\Meridian\Ledger\Enums\TransactionKind;
use App\Domain\Meridian\Ledger\Models\Account;
use App\Domain\Meridian\Ledger\Services\LedgerService;
use App\Domain\Meridian\Offline\Enums\VoucherStatus;
use App\Domain\Meridian\Offline\Exceptions\VoucherBoundException;
use App\Domain\Meridian\Offline\Models\DeferredSettlement;
use App\Domain\Meridian\Offline\Models\OfflineVoucher;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class OfflineVoucherService
{
    public function __construct(private readonly LedgerService $ledger)
    {
    }

    /**
     * Reserve part of a balance into an offline voucher. The
     * reservation is a real balanced ledger transaction, holder
     * authorized (I10): while reserved, the amount cannot be spent
     * online, so conservation survives the offline window.
     */
    public function reserve(
        Account $holder,
        int $amountMinor,
        string $holderPublicKeyBase64,
        string $holderAuthorizationRef,
        int $expiryDays = 30,
        bool $custodialTier = false,
        bool $custodialDisclosureAcknowledged = false,
    ): OfflineVoucher {
        if ($amountMinor <= 0) {
            throw new \InvalidArgumentException('OFFLINE: a reservation must be a positive amount.');
        }

        if ((int) $holder->balance_minor < $amountMinor) {
            throw new VoucherBoundException(
                'OFFLINE: cannot reserve more than the held balance; the voucher bound must be fully funded.'
            );
        }

        $decodedKey = base64_decode($holderPublicKeyBase64, true);

        if ($decodedKey === false || strlen($decodedKey) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
            throw new \InvalidArgumentException('OFFLINE: the holder key must be a valid Ed25519 public key.');
        }

        if ($custodialTier && ! $custodialDisclosureAcknowledged) {
            throw new \DomainException(
                'OFFLINE: the custodial tier is an informed trade (Sidq); it requires an explicit acknowledged disclosure.'
            );
        }

        $currency = $holder->currency()->firstOrFail();
        $reservationAccount = $this->reservationAccount($currency->id);

        return DB::transaction(function () use ($holder, $amountMinor, $holderPublicKeyBase64, $holderAuthorizationRef, $expiryDays, $custodialTier, $custodialDisclosureAcknowledged, $reservationAccount): OfflineVoucher {
            $voucherId = strtolower((string) Str::ulid());

            $transaction = $this->ledger->post(new TransactionDraft(
                kind: TransactionKind::Reservation,
                entries: [
                    new EntryDraft($holder->id, $holder->currency_id, -$amountMinor, holderAuthorizationRef: $holderAuthorizationRef),
                    new EntryDraft($reservationAccount->id, $holder->currency_id, $amountMinor),
                ],
                idempotencyKey: 'voucher-reserve:'.$voucherId,
                metadata: ['voucher_id' => $voucherId],
            ));

            $voucher = new OfflineVoucher([
                'id' => $voucherId,
                'account_id' => $holder->id,
                'currency_id' => $holder->currency_id,
                'reserved_amount_minor' => $amountMinor,
                'settled_amount_minor' => 0,
                'reservation_transaction_id' => $transaction->id,
                'holder_public_key' => $holderPublicKeyBase64,
                'status' => VoucherStatus::Reserved,
                'expires_at' => Carbon::now()->addDays($expiryDays),
                'custodial_tier' => $custodialTier,
                'custodial_disclosure_acknowledged' => $custodialDisclosureAcknowledged,
            ]);
            $voucher->save();

            return $voucher;
        });
    }

    /**
     * Settle one offline-signed deferred record on reconnection.
     * Validates the holder's Ed25519 signature over the binding
     * message, enforces the per-voucher bound and the per-nonce replay
     * bound, then posts the settlement out of the reservation account.
     */
    public function settleDeferred(
        OfflineVoucher $voucher,
        Account $payee,
        int $amountMinor,
        string $nonce,
        string $holderSignatureBase64,
    ): DeferredSettlement {
        if ($amountMinor <= 0) {
            throw new \InvalidArgumentException('OFFLINE: a deferred settlement must be a positive amount.');
        }

        if ($voucher->status !== VoucherStatus::Reserved) {
            throw new VoucherBoundException(sprintf('OFFLINE: a %s voucher accepts no further settlement.', $voucher->status->value));
        }

        if (Carbon::now()->greaterThan($voucher->expires_at)) {
            throw new VoucherBoundException('OFFLINE: the voucher has expired; the unspent reservation returns to the holder.');
        }

        if ($payee->currency_id !== $voucher->currency_id) {
            throw new VoucherBoundException('OFFLINE: a voucher settles only in its own currency.');
        }

        // The bound: you cannot offline-spend more than you reserved.
        if ($amountMinor > $voucher->remainingMinor()) {
            throw new VoucherBoundException(sprintf(
                'OFFLINE: settlement of %d exceeds the voucher\'s remaining bound of %d; '
                .'the per-voucher double-spend exposure is capped at the reserved amount.',
                $amountMinor,
                $voucher->remainingMinor(),
            ));
        }

        // The offline signature: an intercepted or forged record fails here.
        $publicKey = base64_decode($voucher->holder_public_key, true);
        $signature = base64_decode($holderSignatureBase64, true);

        if (
            $publicKey === false || $signature === false
            || strlen($publicKey) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES
            || strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES
            || ! sodium_crypto_sign_verify_detached(
                $signature,
                self::deferredMessage($voucher->id, $payee->id, $amountMinor, $nonce),
                $publicKey,
            )
        ) {
            throw new VoucherBoundException('OFFLINE: the holder signature does not verify; an unsigned offline spend is no spend.');
        }

        return DB::transaction(function () use ($voucher, $payee, $amountMinor, $nonce, $holderSignatureBase64): DeferredSettlement {
            // Row-lock the voucher: concurrent submissions contend here,
            // and the DB CHECK (settled <= reserved) backstops the bound.
            /** @var OfflineVoucher $locked */
            $locked = OfflineVoucher::query()->lockForUpdate()->findOrFail($voucher->id);

            if ($amountMinor > $locked->remainingMinor()) {
                throw new VoucherBoundException('OFFLINE: concurrent settlements exhausted the voucher bound.');
            }

            try {
                $record = new DeferredSettlement([
                    'voucher_id' => $locked->id,
                    'payee_account_id' => $payee->id,
                    'amount_minor' => $amountMinor,
                    'nonce' => $nonce,
                    'holder_signature' => $holderSignatureBase64,
                    'status' => 'settled',
                ]);
                $record->save();
            } catch (UniqueConstraintViolationException) {
                throw new VoucherBoundException(
                    'OFFLINE: this nonce has already settled against this voucher; replay is refused.'
                );
            }

            $reservationAccount = $this->reservationAccount($locked->currency_id);

            $transaction = $this->ledger->post(new TransactionDraft(
                kind: TransactionKind::Settlement,
                entries: [
                    new EntryDraft($reservationAccount->id, $locked->currency_id, -$amountMinor),
                    new EntryDraft($payee->id, $locked->currency_id, $amountMinor),
                ],
                idempotencyKey: 'voucher-settle:'.$locked->id.':'.$nonce,
                metadata: ['voucher_id' => $locked->id, 'deferred_nonce' => $nonce],
            ));

            $record->settlement_transaction_id = $transaction->id;
            $record->save();

            $locked->settled_amount_minor = $locked->settled_amount_minor + $amountMinor;
            $locked->save();

            return $record;
        });
    }

    /**
     * Expire a voucher past its window: the unspent remainder returns
     * to the holder's main balance (DOCUMENT 6.3 §2).
     */
    public function expire(OfflineVoucher $voucher): OfflineVoucher
    {
        if ($voucher->status !== VoucherStatus::Reserved) {
            return $voucher;
        }

        if (Carbon::now()->lessThanOrEqualTo($voucher->expires_at)) {
            throw new \DomainException('OFFLINE: the voucher window has not ended; expiry cannot be forced early.');
        }

        return DB::transaction(function () use ($voucher): OfflineVoucher {
            /** @var OfflineVoucher $locked */
            $locked = OfflineVoucher::query()->lockForUpdate()->findOrFail($voucher->id);

            $remainder = $locked->remainingMinor();

            if ($remainder > 0) {
                $reservationAccount = $this->reservationAccount($locked->currency_id);

                $this->ledger->post(new TransactionDraft(
                    kind: TransactionKind::Transfer,
                    entries: [
                        new EntryDraft($reservationAccount->id, $locked->currency_id, -$remainder),
                        new EntryDraft($locked->account_id, $locked->currency_id, $remainder),
                    ],
                    idempotencyKey: 'voucher-expire:'.$locked->id,
                    metadata: ['voucher_id' => $locked->id],
                ));
            }

            $locked->status = VoucherStatus::Expired;
            $locked->save();

            return $locked;
        });
    }

    /**
     * The message the holder signs offline — binds voucher, payee,
     * amount, and nonce, so a record cannot be redirected or replayed.
     */
    public static function deferredMessage(string $voucherId, string $payeeAccountId, int $amountMinor, string $nonce): string
    {
        return implode('|', ['offline-deferred', $voucherId, $payeeAccountId, (string) $amountMinor, $nonce]);
    }

    private function reservationAccount(string $currencyId): Account
    {
        $account = Account::query()
            ->where('currency_id', $currencyId)
            ->where('system_role', SystemAccountRole::Reservation->value)
            ->first();

        if ($account !== null) {
            return $account;
        }

        $account = new Account([
            'owner_id' => 'system',
            'owner_type' => 'system',
            'currency_id' => $currencyId,
            'type' => \App\Domain\Meridian\Ledger\Enums\AccountType::System,
            'system_role' => SystemAccountRole::Reservation,
        ]);
        $account->save();

        return $account;
    }
}
