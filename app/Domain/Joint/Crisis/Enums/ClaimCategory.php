<?php

/**
 * SPDX-License-Identifier: LicenseRef-MVL-2.0 AND LicenseRef-AVL-2.0
 * DEV-8 / DOCUMENT 8.2 — what the fund covers and what it never will. © Maher
 */

declare(strict_types=1);

namespace App\Domain\Joint\Crisis\Enums;

enum ClaimCategory: string
{
    /** The ONLY compensable category: a loss the body itself caused. */
    case ProtocolBug = 'protocol_bug';

    /** Never covered: the value of a held asset falling. */
    case MarketRisk = 'market_risk';

    /** Never covered: wrong address, lost keys without recovery, phishing outside any defect. */
    case UserError = 'user_error';

    /** Never covered: losses on instruments whose risk was disclosed and accepted. */
    case DisclosedExperimental = 'disclosed_experimental';

    public function compensable(): bool
    {
        return $this === self::ProtocolBug;
    }
}
