<?php

/**
 * SPDX-License-Identifier: LicenseRef-MVL-2.0
 *
 * DEV-7.1 — thrown by the validating ingress when a proposal fails
 * I1–I11. Rejection is the isolation property working: a compromised
 * Aevum produces rejected proposals, never an invalid entry. © Maher
 */

declare(strict_types=1);

namespace App\Domain\Meridian\Ingress\Exceptions;

final class ProposalRejectedException extends \DomainException
{
}
