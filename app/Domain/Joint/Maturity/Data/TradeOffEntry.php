<?php

/**
 * SPDX-License-Identifier: LicenseRef-MVL-2.0 AND LicenseRef-AVL-2.0
 *
 * DOCUMENT 10.1 / DOCUMENT 1.1 — one row of the public Trade-off
 * Register: a named design trade-off, what was chosen, what was
 * knowingly given up, and where the honest caveat lives in the spec set.
 * The register exists so the cost of every design choice is public —
 * a trade-off hidden is a user deceived (Sidq).
 *
 * © Maher
 */

declare(strict_types=1);

namespace App\Domain\Joint\Maturity\Data;

final readonly class TradeOffEntry
{
    /**
     * @param non-empty-string $id stable identifier
     * @param non-empty-string $axis the tension, e.g. "privacy-vs-compliance"
     * @param non-empty-string $chosen what the design chose
     * @param non-empty-string $cost what that choice honestly costs
     * @param non-empty-string $specSource the governing document
     */
    public function __construct(
        public string $id,
        public string $axis,
        public string $chosen,
        public string $cost,
        public string $specSource,
    ) {
    }

    /** @return array<string, string> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'axis' => $this->axis,
            'chosen' => $this->chosen,
            'cost' => $this->cost,
            'spec_source' => $this->specSource,
        ];
    }
}
