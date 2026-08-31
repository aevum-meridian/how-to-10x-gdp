<?php

/**
 * SPDX-License-Identifier: LicenseRef-MVL-2.0
 *
 * DEV-4.3 — the three permitted clawback targets (DOCUMENT 4.3). The
 * enum's exhaustiveness IS the constraint: there is no case for a
 * generic personal-account target, mirroring the DB CHECK. © Maher
 */

declare(strict_types=1);

namespace App\Domain\Meridian\Dispute\Enums;

enum ClawbackTarget: string
{
    case AttesterBond = 'attester_bond';
    case IssuerBond = 'issuer_bond';
    case SpecificFraudulentMint = 'specific_fraudulent_mint';
}
