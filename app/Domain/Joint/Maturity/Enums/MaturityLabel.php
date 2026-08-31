<?php

/**
 * SPDX-License-Identifier: LicenseRef-MVL-2.0 AND LicenseRef-AVL-2.0
 *
 * DOCUMENT 3.4 §1 — the four labels. "Shipped — built, audited where
 * required, in production, and honestly available. InDevelopment —
 * actively being built, not yet production-ready, must not be presented
 * as available. Research — an open problem, possibly unsolved at scale,
 * shipped (if at all) only as honestly-labelled partial coverage.
 * DeprecatedRemoved — retired, kept in the record for historical honesty."
 *
 * © Maher
 */

declare(strict_types=1);

namespace App\Domain\Joint\Maturity\Enums;

enum MaturityLabel: string
{
    case Shipped = 'shipped';
    case InDevelopment = 'in_development';
    case Research = 'research';
    case DeprecatedRemoved = 'deprecated_removed';

    /**
     * AVL-2.0 §A-§C.13: may this capability be presented as available?
     * Only Shipped may. This single method is the binding check the
     * marketing, documentation, and API surfaces must consult.
     */
    public function presentableAsAvailable(): bool
    {
        return $this === self::Shipped;
    }
}
