<?php

/**
 * SPDX-License-Identifier: LicenseRef-AVL-2.0
 *
 * DEV-4.4 / A-§C.10 — thrown when a global block attempts to bypass the
 * timelocked, publicly-justified, appealable constitutional process.
 * Silent or unilateral global blocking is a breach: a system that can
 * blacklist a national currency wields a foreign-policy power, and Adl
 * requires that power be visible, costly, and contestable. © Maher
 */

declare(strict_types=1);

namespace App\Domain\Aevum\Fabric\Exceptions;

final class ConstitutionalProcessException extends \DomainException
{
}
