<?php

/**
 * SPDX-License-Identifier: LicenseRef-MVL-2.0 AND LicenseRef-AVL-2.0
 *
 * DEV-7.2 — the nightly job's report: every belief matched, or the
 * exact drifts found. Detection is the job's ONLY power — the report
 * carries alerts, never corrections. © Maher
 */

declare(strict_types=1);

namespace App\Domain\Joint\Reconciliation\Data;

final readonly class ReconciliationReport
{
    /**
     * @param list<array{type: string, event_id?: string, transaction_id?: string, detail: string}> $drifts
     * @param list<string> $alertEventIds
     */
    public function __construct(
        public int $confirmationsChecked,
        public int $transactionsChecked,
        public array $drifts,
        public array $alertEventIds,
    ) {
    }

    public function clean(): bool
    {
        return $this->drifts === [];
    }
}
