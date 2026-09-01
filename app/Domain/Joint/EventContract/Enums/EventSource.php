<?php

/**
 * SPDX-License-Identifier: LicenseRef-MVL-2.0 AND LicenseRef-AVL-2.0
 * DEV-7.1 — which leg emitted the event. © Maher
 */

declare(strict_types=1);

namespace App\Domain\Joint\EventContract\Enums;

enum EventSource: string
{
    case Aevum = 'aevum';
    case Meridian = 'meridian';
}
