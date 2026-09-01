<?php

/**
 * SPDX-License-Identifier: LicenseRef-MVL-2.0 AND LicenseRef-AVL-2.0
 *
 * GET /api/v1/maturity — DOCUMENT 10.1 / DOCUMENT 3.4.
 *
 * "Uniquely and bindingly — GET /api/v1/maturity (the maturity endpoint
 * every other surface must check)." Public, unauthenticated, cacheable:
 * honesty about readiness is not a privilege, it is the product.
 *
 * © Maher
 */

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Joint\Maturity\Data\MaturityEntry;
use App\Domain\Joint\Maturity\MaturityLedger;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

final class MaturityController extends Controller
{
    public function __invoke(): JsonResponse
    {
        return response()->json([
            'ledger' => 'DOCUMENT 3.4 — THE MATURITY & ABANDONMENT LEDGER',
            'binding' => 'AVL-2.0 §A-§C.13: no surface may present a capability whose label is not "shipped" as available.',
            'labels' => [
                'shipped' => 'built, audited where required, in production, and honestly available',
                'in_development' => 'actively being built, not yet production-ready, must not be presented as available',
                'research' => 'an open problem, possibly unsolved at scale, shipped (if at all) only as honestly-labelled partial coverage',
                'deprecated_removed' => 'retired, kept in the record for historical honesty',
            ],
            'entries' => array_map(
                static fn (MaturityEntry $entry): array => $entry->toArray(),
                MaturityLedger::all(),
            ),
            'honest_note' => 'Nothing in this deployment is Shipped: the invariant suite is green, but Shipped additionally requires the independent external audits (DOCUMENT 9.4) and production operation. The scale claim (eight-billion-users) is Research, and its abandonment criterion revises the claim downward before ever relaxing a safety floor.',
        ]);
    }
}
