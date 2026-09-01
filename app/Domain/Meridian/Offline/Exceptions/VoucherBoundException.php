<?php

/**
 * SPDX-License-Identifier: LicenseRef-MVL-2.0
 *
 * DEV-6.x / DOCUMENT 6.3 §2/§5 — thrown when a deferred settlement
 * would exceed the per-voucher double-spend bound, replay a nonce,
 * fail signature verification, or touch a closed/expired voucher.
 * © Maher
 */

declare(strict_types=1);

namespace App\Domain\Meridian\Offline\Exceptions;

final class VoucherBoundException extends \DomainException
{
}
