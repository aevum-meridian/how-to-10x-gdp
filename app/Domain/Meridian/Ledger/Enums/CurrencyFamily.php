<?php

/**
 * SPDX-License-Identifier: LicenseRef-MVL-2.0
 * DOCUMENT 4.1 — MERIDIAN LEDGER CORE (data model). © Maher
 */

declare(strict_types=1);

namespace App\Domain\Meridian\Ledger\Enums;

enum CurrencyFamily: string
{
    case Reserve = 'reserve';
    case Distributed = 'distributed';
    case Contribution = 'contribution';
}
