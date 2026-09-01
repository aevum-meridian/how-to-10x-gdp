<?php

/**
 * SPDX-License-Identifier: LicenseRef-MVL-2.0
 * DEV-4.2 — thrown when a reserve mint would exceed attested custody, or
 * a bridged representation lacks a confirmed source-chain lock. © Maher
 */

declare(strict_types=1);

namespace App\Domain\Meridian\Issuance\Exceptions;

final class ReserveExceededException extends \DomainException
{
}
