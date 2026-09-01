<?php

/**
 * SPDX-License-Identifier: LicenseRef-MVL-2.0 AND LicenseRef-AVL-2.0
 *
 * DEV-6.x / DOCUMENT 6.4 §3 — thrown when an ingestion carries raw
 * sensitive data, touches the neural red line, or names an
 * unrecognized proof system. © Maher
 */

declare(strict_types=1);

namespace App\Domain\Joint\Disclosure\Exceptions;

final class SensitiveDataRejectedException extends \DomainException
{
}
