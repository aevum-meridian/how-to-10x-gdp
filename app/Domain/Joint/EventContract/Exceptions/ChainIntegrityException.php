<?php

/**
 * SPDX-License-Identifier: LicenseRef-MVL-2.0 AND LicenseRef-AVL-2.0
 *
 * DEV-7.1 — thrown when an event fails the chain test: a broken link,
 * a hash that does not recompute, or a signature that does not verify
 * against the originating leg's registered key. © Maher
 */

declare(strict_types=1);

namespace App\Domain\Joint\EventContract\Exceptions;

final class ChainIntegrityException extends \DomainException
{
}
