<?php

/**
 * SPDX-License-Identifier: LicenseRef-MVL-2.0
 * DEV-4.3 — dispute lifecycle states (DOCUMENT 4.3). © Maher
 */

declare(strict_types=1);

namespace App\Domain\Meridian\Dispute\Enums;

enum DisputeStatus: string
{
    case Open = 'open';
    case Escalated = 'escalated';
    case Arbitrating = 'arbitrating';
    case ResolvedFraud = 'resolved_fraud';
    case ResolvedValid = 'resolved_valid';

    public function isClosed(): bool
    {
        return $this === self::ResolvedFraud || $this === self::ResolvedValid;
    }
}
