<?php

/**
 * SPDX-License-Identifier: LicenseRef-MVL-2.0
 * DOCUMENT 4.1 — the ISSUANCE / BURN / FEE system accounts provisioned
 * per currency. © Maher
 */

declare(strict_types=1);

namespace App\Domain\Meridian\Ledger\Enums;

enum SystemAccountRole: string
{
    case Issuance = 'issuance';
    case Burn = 'burn';
    case Fee = 'fee';
    case Reservation = 'reservation';
}
