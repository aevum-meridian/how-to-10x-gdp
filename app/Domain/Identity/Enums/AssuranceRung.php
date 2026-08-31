<?php

/**
 * SPDX-License-Identifier: LicenseRef-MVL-2.0 AND LicenseRef-AVL-2.0
 *
 * The four-rung identity ladder, per DOCUMENT 6.2 — IDENTITY LADDER &
 * SOCIAL-RECOVERY SPECIFICATION §1. Rung 0 is open to everyone instantly;
 * higher rungs unlock more, gated by progressively stronger verification.
 *
 * © Maher
 */

declare(strict_types=1);

namespace App\Domain\Identity\Enums;

enum AssuranceRung: int
{
    /** Pseudonymous wallet: hold, swap, basic transfers. No verification. */
    case Rung0 = 0;

    /** Social attestation: probationary $UNA pool (hard constitutional cap). */
    case Rung1 = 1;

    /** Document / institutional: regulated features where law requires. */
    case Rung2 = 2;

    /** Full personhood: full $UNA, one-human-one-vote. */
    case Rung3 = 3;
}
