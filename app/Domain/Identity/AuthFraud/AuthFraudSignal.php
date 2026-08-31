<?php

/**
 * SPDX-License-Identifier: LicenseRef-MVL-2.0 AND LicenseRef-AVL-2.0
 *
 * THE HARD ANTI-CORRELATION WALL — DEV-6.1, DOCUMENT 6.1 §5, I9,
 * MVL-2.0 §M-§C.11, AVL-2.0 §A-§C.11.
 *
 * GAS's cross-relying-party reputation graph and behavioral signals are
 * confined to this single type, usable ONLY for authentication-time fraud
 * prevention. It is structurally prevented from reaching any
 * value-affecting, minting, personhood-aggregation, or rights-affecting
 * decision:
 *
 *  - No class in App\Domain\Meridian\*, App\Domain\Aevum\*, or
 *    App\Domain\Identity\Aggregation\* may reference this namespace.
 *    The PersonhoodBoundaryTest (the I9 acceptance test) enforces this as
 *    a build-failing architectural rule — the PHP analog of "the dependency
 *    fails to compile across the boundary" — and asserts at runtime that
 *    no cross-context score reaches any value decision.
 *
 * A person's GAS fraud-risk score can gate WHETHER THEY AUTHENTICATE; it
 * can never influence whether or how much they mint, whether their credits
 * are touched, or whether their rights are gated.
 *
 * © Maher
 */

declare(strict_types=1);

namespace App\Domain\Identity\AuthFraud;

final readonly class AuthFraudSignal
{
    public function __construct(
        public string $providerId,
        public string $opaqueSubjectId,
        public float $fraudRiskScore,
        public string $signalKind,
        public \DateTimeImmutable $observedAt,
    ) {
    }

    /**
     * The only decision this type may inform: authentication-time step-up
     * or refusal. It exposes no personhood, value, or rights semantics.
     */
    public function requiresStepUp(float $threshold): bool
    {
        return $this->fraudRiskScore >= $threshold;
    }
}
