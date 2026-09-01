<?php

/**
 * SPDX-License-Identifier: LicenseRef-MVL-2.0 AND LicenseRef-AVL-2.0
 *
 * DEV-6.x / DOCUMENT 6.2 §3 — thrown when a social recovery attempt
 * tries to skip the timelocked, contestable, M-of-N process. © Maher
 */

declare(strict_types=1);

namespace App\Domain\Identity\Exceptions;

final class RecoveryProcessException extends \DomainException
{
}
