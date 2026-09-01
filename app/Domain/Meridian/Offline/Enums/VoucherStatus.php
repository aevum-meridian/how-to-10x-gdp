<?php

/**
 * SPDX-License-Identifier: LicenseRef-MVL-2.0
 *
 * DEV-6.x / DOCUMENT 6.3 §2 — voucher lifecycle. Reserved accepts
 * deferred settlement within the bound; Closed and Expired accept
 * nothing further (the unspent remainder returns to the holder).
 * © Maher
 */

declare(strict_types=1);

namespace App\Domain\Meridian\Offline\Enums;

enum VoucherStatus: string
{
    case Reserved = 'reserved';
    case Closed = 'closed';
    case Expired = 'expired';
}
