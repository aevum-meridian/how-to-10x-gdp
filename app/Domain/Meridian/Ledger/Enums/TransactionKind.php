<?php

/**
 * SPDX-License-Identifier: LicenseRef-MVL-2.0
 *
 * The typed transaction-kind inventory — the code mirror of the transition
 * inventory in DOCUMENT 5.2 §2 (the Non-Punishment Proof). Every kind that
 * can carry a debit against a personal contribution account is either
 * holder-authorized (Transfer/HolderSpend/Settlement, gated by I10) or the
 * single ArbitrationReversal path (gated by the I6-revised predicate).
 * There is no third path.
 *
 * © Maher
 */

declare(strict_types=1);

namespace App\Domain\Meridian\Ledger\Enums;

enum TransactionKind: string
{
    case Transfer = 'transfer';
    case HolderSpend = 'holder_spend';
    case Mint = 'mint';
    case Burn = 'burn';
    case Reversal = 'reversal';
    case ArbitrationReversal = 'arbitration_reversal';
    case Settlement = 'settlement';
    case Reservation = 'reservation';

    /**
     * Kinds whose debits against a personal account require a
     * holder-authorization reference (I10).
     */
    public function requiresHolderAuthorizationForPersonalDebit(): bool
    {
        return match ($this) {
            self::Transfer, self::HolderSpend, self::Settlement, self::Reservation => true,
            default => false,
        };
    }
}
