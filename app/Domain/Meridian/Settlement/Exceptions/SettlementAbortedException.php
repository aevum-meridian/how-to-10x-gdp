<?php

/**
 * SPDX-License-Identifier: LicenseRef-MVL-2.0
 *
 * DEV-7.x — thrown when a settlement aborts. The abort is a rollback
 * that issues no entries: prior state is restored exactly, and no
 * personal balance is net-debited. The exception carries the reason;
 * the ledger carries nothing. © Maher
 */

declare(strict_types=1);

namespace App\Domain\Meridian\Settlement\Exceptions;

final class SettlementAbortedException extends \RuntimeException
{
}
