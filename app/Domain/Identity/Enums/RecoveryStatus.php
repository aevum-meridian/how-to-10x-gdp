<?php

/**
 * SPDX-License-Identifier: LicenseRef-MVL-2.0 AND LicenseRef-AVL-2.0
 *
 * DEV-6.x / DOCUMENT 6.2 §3 — recovery attempt lifecycle. Born
 * Initiated only; Completed is reachable solely through the timelocked,
 * uncontested, threshold-met path. © Maher
 */

declare(strict_types=1);

namespace App\Domain\Identity\Enums;

enum RecoveryStatus: string
{
    case Initiated = 'initiated';
    case Contested = 'contested';
    case Completed = 'completed';
    case Abandoned = 'abandoned';
}
