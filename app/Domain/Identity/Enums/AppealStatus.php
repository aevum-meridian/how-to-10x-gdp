<?php

/**
 * SPDX-License-Identifier: LicenseRef-MVL-2.0 AND LicenseRef-AVL-2.0
 *
 * DEV-6.x / DOCUMENT 6.1 §4 — the appeal state of a personhood
 * determination. Denial of personhood is a high-risk consequential
 * decision, so every determination carries an appeal path. © Maher
 */

declare(strict_types=1);

namespace App\Domain\Identity\Enums;

enum AppealStatus: string
{
    case None = 'none';
    case Open = 'open';
    case Upheld = 'upheld';
    case Denied = 'denied';
}
