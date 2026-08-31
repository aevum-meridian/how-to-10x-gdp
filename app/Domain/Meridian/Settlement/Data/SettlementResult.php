<?php

/**
 * SPDX-License-Identifier: LicenseRef-MVL-2.0
 *
 * DEV-7.x — the outcome of a settlement: committed with the posted
 * transaction ids (one balanced transaction per leg, posted inside
 * ONE database transaction), or it never happened at all. There is no
 * third state — that absence IS the abort-path guarantee. © Maher
 */

declare(strict_types=1);

namespace App\Domain\Meridian\Settlement\Data;

final readonly class SettlementResult
{
    /** @param list<string> $transactionIds */
    public function __construct(
        public string $settlementRef,
        public array $transactionIds,
    ) {
    }
}
