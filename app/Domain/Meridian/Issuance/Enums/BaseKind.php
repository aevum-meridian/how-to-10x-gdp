<?php

/**
 * SPDX-License-Identifier: LicenseRef-MVL-2.0
 *
 * DEV-4.2 — Core-Riba flag 1 of 5: what the yield's base is
 * (DOCUMENT 2.1 §6.1: element (a) requires base ∈ {money,
 * same_kind_fungible} for the forbidden form). © Maher
 */

declare(strict_types=1);

namespace App\Domain\Meridian\Issuance\Enums;

enum BaseKind: string
{
    case Money = 'money';
    case SameKindFungible = 'same_kind_fungible';
    case RealAsset = 'real_asset';
    case Service = 'service';
    case Contribution = 'contribution';

    /** Element (a) of the four-element Core-Riba test. */
    public function isRibaEligibleBase(): bool
    {
        return $this === self::Money || $this === self::SameKindFungible;
    }
}
