<?php

/**
 * SPDX-License-Identifier: LicenseRef-AVL-2.0
 * DEV-4.4 — multi-sourced, contestable asset-label categories. © Maher
 */

declare(strict_types=1);

namespace App\Domain\Aevum\Fabric\Enums;

enum LabelCategory: string
{
    case Weapons = 'weapons';
    case Gambling = 'gambling';
    case Usury = 'usury';
    case Adult = 'adult';
    case Surveillance = 'surveillance';
    case EnvironmentalHarm = 'environmental_harm';
    case Sanctioned = 'sanctioned';
    case Other = 'other';
}
