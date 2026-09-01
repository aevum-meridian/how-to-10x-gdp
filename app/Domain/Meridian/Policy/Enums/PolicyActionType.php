<?php

/**
 * SPDX-License-Identifier: LicenseRef-MVL-2.0
 * DEV-4.5 — the complete inventory of Policy Engine action kinds. The
 * exhaustiveness of this enum is part of I7: there is no action kind
 * that authors a ledger entry. © Maher
 */

declare(strict_types=1);

namespace App\Domain\Meridian\Policy\Enums;

enum PolicyActionType: string
{
    case AdjustIssuancePolicy = 'adjust_issuance_policy';
    case FireCircuitBreaker = 'fire_circuit_breaker';
    case ClearCircuitBreaker = 'clear_circuit_breaker';
    case EvaluateProxyDivergence = 'evaluate_proxy_divergence';
}
