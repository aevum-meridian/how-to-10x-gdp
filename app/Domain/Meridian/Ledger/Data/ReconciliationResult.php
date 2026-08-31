<?php

/**
 * SPDX-License-Identifier: LicenseRef-MVL-2.0
 *
 * DOCUMENT 4.1 — reconcile(Account): ReconciliationResult. Discrepancies
 * ALERT, NEVER AUTO-CORRECT: this result reports; it changes nothing.
 *
 * © Maher
 */

declare(strict_types=1);

namespace App\Domain\Meridian\Ledger\Data;

use Spatie\LaravelData\Data;

final class ReconciliationResult extends Data
{
    public function __construct(
        public readonly string $accountId,
        public readonly int $storedBalanceMinor,
        public readonly int $recomputedBalanceMinor,
        public readonly bool $consistent,
    ) {
    }
}
