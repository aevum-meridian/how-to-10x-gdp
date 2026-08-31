<?php

/**
 * SPDX-License-Identifier: LicenseRef-MVL-2.0
 *
 * I6 — a debit against a personal contribution account referencing neither
 * a holder authorization nor (a specific fraudulent mint + a closed
 * arbitration case) is a PUNITIVE DEBIT and is always rejected. This
 * exception is the service-layer face of that rejection; the DB trigger
 * is the structural one.
 *
 * © Maher
 */

declare(strict_types=1);

namespace App\Domain\Meridian\Ledger\Exceptions;

final class PunitiveDebitException extends \DomainException
{
}
