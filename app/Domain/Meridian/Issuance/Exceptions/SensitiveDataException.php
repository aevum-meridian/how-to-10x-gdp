<?php

/**
 * SPDX-License-Identifier: LicenseRef-MVL-2.0
 * DEV-4.2 — thrown when an attestation payload or currency policy would
 * carry identifiable biometric/health/neural data (I8). © Maher
 */

declare(strict_types=1);

namespace App\Domain\Meridian\Issuance\Exceptions;

final class SensitiveDataException extends \DomainException
{
}
