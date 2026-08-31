<?php

/**
 * SPDX-License-Identifier: LicenseRef-AVL-2.0
 *
 * DEV-4.4 / A-§C.9 — thrown when a currency experience whose underlying
 * issuance policy is Core Riba attempts registration. Defense-in-depth
 * with Meridian's I11: such a policy cannot exist in the system of
 * record anyway, but Aevum refuses to surface it independently. © Maher
 */

declare(strict_types=1);

namespace App\Domain\Aevum\Fabric\Exceptions;

final class CoreRibaExperienceException extends \DomainException
{
}
