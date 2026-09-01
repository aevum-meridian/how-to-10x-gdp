<?php

/**
 * SPDX-License-Identifier: LicenseRef-AVL-2.0
 *
 * DEV-4.4 / A-§C.12 — the Tier-0 verified-stabilizer contract. Every
 * implementer must be a PURE function over its inputs:
 *
 *  - deterministic (same input, same output, always);
 *  - side-effect free (no I/O, no clock, no randomness, no persistence);
 *  - capped (its adjustment magnitude never exceeds movementCap());
 *  - ML-free (no learned component on the trusted path — any ML lives
 *    only in the Tier-1 advisory proposer, which emits inert proposals
 *    and executes nothing).
 *
 * Tier0PurityTest verifies each registered rule against all four
 * properties, and an architectural scan forbids impure/ML imports in
 * this namespace. A single side-effecting or non-deterministic
 * stabilizer breaks the no-ML-on-trusted-path guarantee (DOCUMENT 4.4,
 * honest caveats) — hence the discipline is tested, not trusted.
 *
 * © Maher
 */

declare(strict_types=1);

namespace App\Domain\Aevum\Fabric\Tier0;

interface Tier0Rule
{
    /** A stable, human-auditable identifier for the rule. */
    public function ruleId(): string;

    /**
     * The hard per-epoch movement cap: the maximum absolute relative
     * adjustment this rule may ever emit in one epoch (e.g. 0.02 = 2%).
     */
    public function movementCap(): float;

    /**
     * Compute the stabilizer adjustment. MUST be pure: no I/O, no
     * clock, no randomness — the output is a function of the input
     * and nothing else.
     */
    public function evaluate(Tier0Input $input): Tier0Adjustment;
}
