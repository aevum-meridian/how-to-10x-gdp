<?php

/**
 * SPDX-License-Identifier: LicenseRef-MVL-2.0 AND LicenseRef-AVL-2.0
 *
 * GET /api/v1/currencies — DOCUMENT 10.1: "the currency registry (read
 * public; write governance-gated, refusing Core Riba policies)."
 *
 * This controller is the READ face only. There is deliberately no write
 * route: currency instantiation flows through IssuanceService (which
 * refuses Core Riba at the service layer with the DB CHECK behind it)
 * under governance, never through an HTTP POST. Every currency is
 * returned with its declared issuance-policy flags — the four-element
 * Core Riba test is public information — and its maturity label.
 *
 * © Maher
 */

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Joint\Maturity\Enums\MaturityLabel;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

final class CurrencyRegistryController extends Controller
{
    public function __invoke(): JsonResponse
    {
        /** @var list<object{id: string, code: string, name: string, family: string, decimals: int, type: string|null, base_kind: string|null, increase_kind: string|null, risk_bearing: bool|null, value_creating: bool|null, extracts_from_counterparty: bool|null}> $rows */
        $rows = DB::table('currencies')
            ->leftJoin('issuance_policies', 'issuance_policies.currency_id', '=', 'currencies.id')
            ->orderBy('currencies.code')
            ->get([
                'currencies.id',
                'currencies.code',
                'currencies.name',
                'currencies.family',
                'currencies.decimals',
                'issuance_policies.type',
                'issuance_policies.base_kind',
                'issuance_policies.increase_kind',
                'issuance_policies.risk_bearing',
                'issuance_policies.value_creating',
                'issuance_policies.extracts_from_counterparty',
            ])
            ->all();

        return response()->json([
            'registry' => 'read-only; currency instantiation is governance-gated through the Issuance Engine, which refuses Core Riba policies (I11) at both the service layer and a database CHECK',
            'maturity_note' => 'every listed currency remains '.MaturityLabel::Research->value.' or '.MaturityLabel::InDevelopment->value.' per /api/v1/maturity; listing here is registration, not an availability claim',
            'currencies' => array_map(static fn (object $row): array => [
                'id' => $row->id,
                'code' => $row->code,
                'name' => $row->name,
                'family' => $row->family,
                'decimals' => $row->decimals,
                'issuance_policy' => $row->type === null ? null : [
                    'type' => $row->type,
                    'base_kind' => $row->base_kind,
                    'increase_kind' => $row->increase_kind,
                    'risk_bearing' => (bool) $row->risk_bearing,
                    'value_creating' => (bool) $row->value_creating,
                    'extracts_from_counterparty' => (bool) $row->extracts_from_counterparty,
                ],
            ], $rows),
        ]);
    }
}
