<?php

/**
 * SPDX-License-Identifier: LicenseRef-MVL-2.0 AND LicenseRef-AVL-2.0
 *
 * Maturity labels per DOCUMENT 3.4 (Maturity & Abandonment Ledger) and
 * DEV-10: no Research or InDevelopment capability may ever be exposed
 * through any surface as if it were shipped. The maturity endpoint
 * (GET /api/v1/maturity) is the binding check every surface consults.
 *
 * © Maher
 */

declare(strict_types=1);

namespace App\Domain\Shared\Enums;

enum Maturity: string
{
    case Research = 'research';
    case InDevelopment = 'in_development';
    case Shipped = 'shipped';
    case Deprecated = 'deprecated';

    public function isExposableAsAvailable(): bool
    {
        return $this === self::Shipped;
    }
}
