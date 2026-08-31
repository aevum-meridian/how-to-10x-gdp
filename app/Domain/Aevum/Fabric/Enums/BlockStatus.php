<?php

/**
 * SPDX-License-Identifier: LicenseRef-AVL-2.0
 *
 * DEV-4.4 / A-§C.10 — the constitutional lifecycle of a global block.
 * A block is BORN proposed; it may only become active through the
 * timelocked, publicly-justified, appealable process. © Maher
 */

declare(strict_types=1);

namespace App\Domain\Aevum\Fabric\Enums;

enum BlockStatus: string
{
    case Proposed = 'proposed';
    case Active = 'active';
    case Appealed = 'appealed';
    case Void = 'void';
}
