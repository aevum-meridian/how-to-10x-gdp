<?php

/**
 * SPDX-License-Identifier: LicenseRef-MVL-2.0
 * DOCUMENT 4.1 — reverse(Transaction, ReversalReason). Corrections are
 * additive reversing entries (I5); the arbitration kind is reserved for
 * the Dispute Engine (DOCUMENT 4.3, I6).
 * © Maher
 */

declare(strict_types=1);

namespace App\Domain\Meridian\Ledger\Enums;

enum ReversalReason: string
{
    case ErrorCorrection = 'error_correction';
    case OperationalReversal = 'operational_reversal';
    case ArbitrationReversal = 'arbitration_reversal';
}
