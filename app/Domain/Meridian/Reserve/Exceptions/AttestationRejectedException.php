<?php

/**
 * SPDX-License-Identifier: LicenseRef-MVL-2.0
 * DEV-8.3 — an attestation that cannot be trusted is refused. © Maher
 */

declare(strict_types=1);

namespace App\Domain\Meridian\Reserve\Exceptions;

final class AttestationRejectedException extends \DomainException
{
}
