<?php

/**
 * SPDX-License-Identifier: LicenseRef-MVL-2.0 AND LicenseRef-AVL-2.0
 *
 * DEV-7.1 — the result of a full chain walk: intact or broken, and if
 * broken, exactly where and why. The chain makes tampering DETECTABLE;
 * this is the detector's report. © Maher
 */

declare(strict_types=1);

namespace App\Domain\Joint\EventContract\Data;

final readonly class ChainVerification
{
    /** @param list<array{seq: int, event_id: string, defect: string}> $defects */
    public function __construct(
        public bool $intact,
        public int $eventsVerified,
        public array $defects,
    ) {
    }
}
