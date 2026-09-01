<?php

/**
 * SPDX-License-Identifier: LicenseRef-MVL-2.0
 * DEV-4.2 — thrown when an issuance policy squarely encodes all four
 * Core-Riba elements (I11). No presumption can rescue it. © Maher
 */

declare(strict_types=1);

namespace App\Domain\Meridian\Issuance\Exceptions;

final class CoreRibaPolicyException extends \DomainException
{
}
