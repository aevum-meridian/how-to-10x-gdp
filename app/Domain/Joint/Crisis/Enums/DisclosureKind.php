<?php

/**
 * SPDX-License-Identifier: LicenseRef-MVL-2.0 AND LicenseRef-AVL-2.0
 * DEV-8 / DOCUMENT 8.1 §2 — the three bound disclosures. © Maher
 */

declare(strict_types=1);

namespace App\Domain\Joint\Crisis\Enums;

enum DisclosureKind: string
{
    case StatusPage = 'status_page';
    case PreliminaryReport = 'preliminary_report';
    case Postmortem = 'postmortem';
}
