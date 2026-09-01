<?php

/**
 * SPDX-License-Identifier: LicenseRef-MVL-2.0
 * DOCUMENT 4.1 — MERIDIAN LEDGER CORE. Transactions are inserted already
 * `posted` within the atomic post; a reversal is a NEW transaction carrying
 * reverses_transaction_id (I5 — the original is never mutated, so there is
 * no `reversed` status transition on the original row).
 * © Maher
 */

declare(strict_types=1);

namespace App\Domain\Meridian\Ledger\Enums;

enum TransactionStatus: string
{
    case Posted = 'posted';
}
