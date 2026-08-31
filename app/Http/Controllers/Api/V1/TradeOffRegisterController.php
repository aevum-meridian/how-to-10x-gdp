<?php

/**
 * SPDX-License-Identifier: LicenseRef-MVL-2.0 AND LicenseRef-AVL-2.0
 *
 * GET /api/v1/trade-off-register — DOCUMENT 10.1.
 *
 * The machine-readable Trade-off Register: every design tension this
 * system resolved, what was chosen, and what the choice honestly costs.
 * Public and unauthenticated — a trade-off disclosed only to insiders
 * is not disclosed.
 *
 * © Maher
 */

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Joint\Maturity\Data\TradeOffEntry;
use App\Domain\Joint\Maturity\TradeOffRegister;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

final class TradeOffRegisterController extends Controller
{
    public function __invoke(): JsonResponse
    {
        return response()->json([
            'register' => 'THE TRADE-OFF REGISTER (DOCUMENT 10.1)',
            'purpose' => 'Every entry names a real tension, states what was chosen, and states without softening what the choice costs. Nothing here is a bug report; everything here is a decision the project stands behind and discloses.',
            'entries' => array_map(
                static fn (TradeOffEntry $entry): array => $entry->toArray(),
                TradeOffRegister::all(),
            ),
        ]);
    }
}
