<?php

/**
 * SPDX-License-Identifier: LicenseRef-MVL-2.0
 * DEV-4.3 — thrown when applyArbitrationReversal() is invoked with a
 * resolution failing any conjunct of the I6-revised predicate. © Maher
 */

declare(strict_types=1);

namespace App\Domain\Meridian\Dispute\Exceptions;

final class InvalidReversalException extends \DomainException
{
}
