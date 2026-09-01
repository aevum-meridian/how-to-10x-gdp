<?php

/**
 * SPDX-License-Identifier: LicenseRef-MVL-2.0
 * DOCUMENT 4.1 — MERIDIAN LEDGER CORE (data model). © Maher
 */

declare(strict_types=1);

namespace App\Domain\Meridian\Ledger\Enums;

enum AccountType: string
{
    case Asset = 'asset';
    case Liability = 'liability';
    case Equity = 'equity';
    case Income = 'income';
    case Expense = 'expense';
    case System = 'system';
}
