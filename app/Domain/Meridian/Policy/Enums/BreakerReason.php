<?php

/**
 * SPDX-License-Identifier: LicenseRef-MVL-2.0
 * DEV-4.5 — circuit-breaker trigger reasons (DOCUMENT 4.5). © Maher
 */

declare(strict_types=1);

namespace App\Domain\Meridian\Policy\Enums;

enum BreakerReason: string
{
    case ReserveShortfall = 'reserve_shortfall';
    case AttackSignature = 'attack_signature';
    case DivergenceSpike = 'divergence_spike';
    case AnomalousInput = 'anomalous_input';
}
