<?php

/**
 * SPDX-License-Identifier: LicenseRef-MVL-2.0
 * DEV-4.3 — dispute-profile settlement modes (DOCUMENT 4.3). © Maher
 */

declare(strict_types=1);

namespace App\Domain\Meridian\Dispute\Enums;

enum SettlementMode: string
{
    case Immediate = 'immediate';
    case Provisional = 'provisional';
}
