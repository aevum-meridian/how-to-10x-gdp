<?php

/**
 * SPDX-License-Identifier: LicenseRef-MVL-2.0
 * DEV-4.2 — the three issuance models (DOCUMENT 4.2). © Maher
 */

declare(strict_types=1);

namespace App\Domain\Meridian\Issuance\Enums;

enum IssuanceType: string
{
    case Reserve1To1 = 'reserve_1to1';
    case Bridge = 'bridge';
    case Povc = 'povc';
}
