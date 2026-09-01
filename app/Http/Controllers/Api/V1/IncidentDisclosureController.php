<?php

/**
 * SPDX-License-Identifier: LicenseRef-MVL-2.0 AND LicenseRef-AVL-2.0
 *
 * GET /api/v1/incidents — DOCUMENT 8.1 disclosure commitments, surfaced.
 *
 * The disclosure clock is a public promise: severity, declared_at, the
 * three deadlines, and every published disclosure — including whether a
 * rung is OVERDUE. An incident this endpoint hides is a commitment
 * broken, so it hides nothing: incidents are undeletable at the DB and
 * their deadlines can only ever tighten.
 *
 * © Maher
 */

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Joint\Crisis\Models\Incident;
use App\Domain\Joint\Crisis\Services\CrisisService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

final class IncidentDisclosureController extends Controller
{
    public function __construct(private readonly CrisisService $crisis)
    {
    }

    public function __invoke(): JsonResponse
    {
        $incidents = Incident::query()
            ->orderByDesc('declared_at')
            ->limit(100)
            ->get();

        return response()->json([
            'charter' => 'DOCUMENT 8.1 — disclosure commitments are published in advance and enforced one-way: deadlines never move later, severity is never rewritten, incidents are never deleted, disclosures are append-only',
            'commander_note' => 'the incident commander is a role with NO ledger power; crisis powers are halt and disclose only',
            'incidents' => $incidents->map(fn (Incident $incident): array => [
                'id' => $incident->id,
                'severity' => $incident->severity->value,
                'status' => $incident->status,
                'summary' => $incident->summary,
                'trigger_source' => $incident->trigger_source,
                'declared_at' => $incident->declared_at->toIso8601String(),
                'deadlines' => [
                    'status_page_due_at' => $incident->status_page_due_at->toIso8601String(),
                    'preliminary_report_due_at' => $incident->preliminary_report_due_at->toIso8601String(),
                    'postmortem_due_at' => $incident->postmortem_due_at->toIso8601String(),
                ],
                'disclosures' => $this->crisis->disclosureStatus($incident),
            ])->all(),
        ]);
    }
}
