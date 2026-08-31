<?php

/**
 * SPDX-License-Identifier: LicenseRef-AVL-2.0
 *
 * DEV-4.4 — the ethical filter's three-valued verdict at the experience
 * edge. In the user-sovereign default a disfavored asset is WARNED, and
 * the user decides; REFUSE is mandatory only for constitutional global
 * blocks (and legal screening at the regulated-tunnel face). The filter
 * never authors a balance change in any verdict (A-§C.14). © Maher
 */

declare(strict_types=1);

namespace App\Domain\Aevum\Fabric\Enums;

enum FilterVerdict: string
{
    case Admit = 'admit';
    case Warn = 'warn';
    case Refuse = 'refuse';
}
