<?php

/**
 * SPDX-License-Identifier: LicenseRef-MVL-2.0 AND LicenseRef-AVL-2.0
 *
 * DEV-6.x / DOCUMENT 6.2 §2 — thrown when a Rung-1 pool grant would
 * exceed the hard constitutional cap. The bound on Sybil damage is the
 * cap itself; exceeding it is refused, never queued. © Maher
 */

declare(strict_types=1);

namespace App\Domain\Identity\Exceptions;

final class ConstitutionalCapException extends \DomainException
{
}
