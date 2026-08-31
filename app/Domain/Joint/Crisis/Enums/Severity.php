<?php

/**
 * SPDX-License-Identifier: LicenseRef-MVL-2.0 AND LicenseRef-AVL-2.0
 * DEV-8 / DOCUMENT 8.1 §1 — the graded severity scale. © Maher
 */

declare(strict_types=1);

namespace App\Domain\Joint\Crisis\Enums;

enum Severity: string
{
    /** Confirmed loss of custodied value, reserve shortfall, or a breach of a constitutional invariant. */
    case S1 = 's1';

    /** A breaker fired on real anomaly, reconciliation drift indicating possible loss, personhood compromise. */
    case S2 = 's2';

    /** A contained vulnerability, a non-loss anomaly. */
    case S3 = 's3';

    /** Degraded service without value risk. */
    case S4 = 's4';

    /**
     * The bound disclosure commitments, published IN ADVANCE (§2): minutes
     * to the status page, hours to a preliminary report, days to a full
     * public post-mortem. Tighter for worse failures.
     *
     * @return array{status_page_minutes: int, preliminary_hours: int, postmortem_days: int}
     */
    public function disclosureCommitments(): array
    {
        return match ($this) {
            self::S1 => ['status_page_minutes' => 30, 'preliminary_hours' => 12, 'postmortem_days' => 7],
            self::S2 => ['status_page_minutes' => 60, 'preliminary_hours' => 24, 'postmortem_days' => 14],
            self::S3 => ['status_page_minutes' => 240, 'preliminary_hours' => 72, 'postmortem_days' => 30],
            self::S4 => ['status_page_minutes' => 1440, 'preliminary_hours' => 168, 'postmortem_days' => 60],
        };
    }
}
