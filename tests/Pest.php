<?php

/**
 * SPDX-License-Identifier: LicenseRef-MVL-2.0 AND LicenseRef-AVL-2.0
 * Joint test bootstrap — DOCUMENT 9.2 (THE INVARIANT & PROPERTY TEST SUITE).
 * © Maher
 */

declare(strict_types=1);

pest()->extend(Tests\TestCase::class)->in('Feature', 'Invariants', 'Architecture');
