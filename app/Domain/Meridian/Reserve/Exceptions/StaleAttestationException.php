<?php

/**
 * SPDX-License-Identifier: LicenseRef-MVL-2.0
 * DEV-8.3 — freshness is a parameter with a real-world floor. © Maher
 */

declare(strict_types=1);

namespace App\Domain\Meridian\Reserve\Exceptions;

final class StaleAttestationException extends \DomainException
{
}
