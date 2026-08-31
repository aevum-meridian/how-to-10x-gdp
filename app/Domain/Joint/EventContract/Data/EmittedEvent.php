<?php

/**
 * SPDX-License-Identifier: LicenseRef-MVL-2.0 AND LicenseRef-AVL-2.0
 *
 * DEV-7.1 — the outcome of an append: the stored event plus whether
 * this call actually appended it or an idempotent replay returned the
 * original. © Maher
 */

declare(strict_types=1);

namespace App\Domain\Joint\EventContract\Data;

use App\Domain\Joint\EventContract\Models\CrossSystemEvent;

final readonly class EmittedEvent
{
    public function __construct(
        public CrossSystemEvent $event,
        public bool $replayed,
    ) {
    }
}
