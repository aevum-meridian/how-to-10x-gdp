<?php

/**
 * SPDX-License-Identifier: LicenseRef-MVL-2.0 AND LicenseRef-AVL-2.0
 *
 * DOCUMENT 3.4 §2 — one row of the maturity ledger: a subsystem, its
 * honest label, its exit criterion (proceed when) AND its abandonment
 * criterion (stop/redesign if). "A system intended for a third of
 * humanity needs not only a definition of ready but the courage to
 * define stop."
 *
 * © Maher
 */

declare(strict_types=1);

namespace App\Domain\Joint\Maturity\Data;

use App\Domain\Joint\Maturity\Enums\MaturityLabel;

final readonly class MaturityEntry
{
    /**
     * @param non-empty-string $subsystem
     * @param non-empty-string $exitCriterion proceed when …
     * @param non-empty-string $abandonmentCriterion stop/redesign if …
     */
    public function __construct(
        public string $subsystem,
        public MaturityLabel $label,
        public string $exitCriterion,
        public string $abandonmentCriterion,
    ) {
    }

    /** @return array<string, string|bool> */
    public function toArray(): array
    {
        return [
            'subsystem' => $this->subsystem,
            'label' => $this->label->value,
            'presentable_as_available' => $this->label->presentableAsAvailable(),
            'exit_criterion' => $this->exitCriterion,
            'abandonment_criterion' => $this->abandonmentCriterion,
        ];
    }
}
