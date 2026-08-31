<?php

/**
 * SPDX-License-Identifier: LicenseRef-MVL-2.0 AND LicenseRef-AVL-2.0
 * Joint test base — DOCUMENT 9.2. Uses DatabaseTruncation (not transactions)
 * because the invariant suite must exercise DEFERRED constraint triggers,
 * which fire only at real COMMIT (DOCUMENT 4.1, I1 enforcement).
 * © Maher
 */

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    use DatabaseTruncation;
}
