<?php

/**
 * SPDX-License-Identifier: LicenseRef-MVL-2.0 AND LicenseRef-AVL-2.0
 * DEV-7.1 — the lifecycle of a chained event. © Maher
 */

declare(strict_types=1);

namespace App\Domain\Joint\EventContract\Enums;

enum EventStatus: string
{
    case Emitted = 'emitted';
    case Validated = 'validated';
    case Committed = 'committed';
    case Rejected = 'rejected';
    case Reconciled = 'reconciled';
}
