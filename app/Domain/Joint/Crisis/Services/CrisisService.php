<?php

/**
 * SPDX-License-Identifier: LicenseRef-MVL-2.0 AND LicenseRef-AVL-2.0
 *
 * DEV-8 / DOCUMENT 8.1 — Crisis Runbook & Incident-Commander Charter.
 * © Maher
 *
 * Technical safety stops the machine; THIS is the institutional
 * counterpart: severity S1–S4, disclosure deadlines computed at
 * declaration from commitments published in advance (Severity::
 * disclosureCommitments), and a commander defined BY ROLE, not person.
 *
 * THE MOST IMPORTANT LINE IN THIS MODULE IS WHAT IS ABSENT FROM IT.
 * The commander's emergency powers are HALT (the breaker already fired
 * or fires via the Policy Engine's own guarded path) and DISCLOSE
 * (publish, on the clock). This class imports no ledger writer, holds
 * no account reference, and can touch no balance — "emergency" is never
 * a license to violate the spine, and the Coercion-Resistance Spec's
 * "temporary emergency" attack path is denied by giving the crisis
 * machinery nothing to seize. Enforced three ways: this module's import
 * surface (scanned by CrisisCharterTest), the DB grants (no crisis role
 * touches entries/transactions), and the ledger's own I6/I10 triggers
 * which bind every writer, crisis or not.
 */

declare(strict_types=1);

namespace App\Domain\Joint\Crisis\Services;

use App\Domain\Joint\Crisis\Enums\DisclosureKind;
use App\Domain\Joint\Crisis\Enums\Severity;
use App\Domain\Joint\Crisis\Exceptions\CrisisProcessException;
use App\Domain\Joint\Crisis\Models\Incident;
use App\Domain\Joint\Crisis\Models\IncidentDisclosure;
use Illuminate\Support\Facades\DB;

final class CrisisService
{
    /**
     * Declare an incident. The disclosure clock starts NOW, computed from
     * the severity's pre-published commitments — not negotiated in the
     * moment.
     */
    public function declare(
        Severity $severity,
        string $summary,
        string $triggerSource = 'manual',
        string $commanderRole = 'incident-commander',
    ): Incident {
        if (trim($summary) === '') {
            throw new CrisisProcessException(
                'An incident without a summary discloses nothing. Refused.'
            );
        }

        $clock = $severity->disclosureCommitments();
        $declaredAt = now();

        $incident = new Incident([
            'severity' => $severity,
            'summary' => $summary,
            'commander_role' => $commanderRole,
            'status' => 'open',
            'trigger_source' => $triggerSource,
            'declared_at' => $declaredAt,
            'status_page_due_at' => $declaredAt->copy()->addMinutes($clock['status_page_minutes']),
            'preliminary_report_due_at' => $declaredAt->copy()->addHours($clock['preliminary_hours']),
            'postmortem_due_at' => $declaredAt->copy()->addDays($clock['postmortem_days']),
        ]);
        $incident->save();

        return $incident;
    }

    /**
     * DOCUMENT 8.3 §4 / 8.1 §4 — the automatic trigger: a reserve
     * shortfall declares an S1 without waiting for human discovery. The
     * disclosure clock starts at the moment of the attested shortfall.
     */
    public function declareReserveShortfall(string $currencyCode, int $attestedMinor, int $outstandingMinor): Incident
    {
        return $this->declare(
            Severity::S1,
            "Reserve shortfall attested for {$currencyCode}: attested reserves {$attestedMinor} are below "
            ."net issuance {$outstandingMinor}. Automatic issuance is halted by the circuit breaker; "
            .'the disclosure clock started automatically at attestation.',
            triggerSource: 'reserve_attestation',
        );
    }

    /**
     * Publish one of the three bound disclosures. Publication is
     * append-only (DB trigger) and each kind is publishable exactly once.
     */
    public function publish(Incident $incident, DisclosureKind $kind, string $content): IncidentDisclosure
    {
        if (trim($content) === '') {
            throw new CrisisProcessException(
                'An empty disclosure is silence wearing a timestamp. Refused.'
            );
        }

        if ($incident->status !== 'open' && $kind !== DisclosureKind::Postmortem) {
            throw new CrisisProcessException(
                'Only the post-mortem may follow closure; the status page and preliminary report belong to the open incident.'
            );
        }

        $disclosure = new IncidentDisclosure([
            'incident_id' => $incident->id,
            'kind' => $kind,
            'content' => $content,
        ]);
        $disclosure->save();

        return $disclosure;
    }

    /**
     * Close an incident. Closure REQUIRES the full disclosure ladder —
     * an incident cannot be quietly closed with its truth untold.
     */
    public function close(Incident $incident): Incident
    {
        return DB::transaction(function () use ($incident): Incident {
            $published = IncidentDisclosure::query()
                ->where('incident_id', $incident->id)
                ->get()
                ->map(static fn (IncidentDisclosure $d): string => $d->kind->value)
                ->all();

            foreach (DisclosureKind::cases() as $required) {
                if (! in_array($required->value, $published, true)) {
                    throw new CrisisProcessException(
                        "Incident {$incident->id} cannot close: the {$required->value} has not been published. "
                        .'The body fails openly or the incident stays open.'
                    );
                }
            }

            $incident->status = 'closed';
            $incident->closed_at = now();
            $incident->save();

            return $incident;
        });
    }

    /**
     * The public state of the disclosure clock — which commitments are
     * met, which are pending, which are OVERDUE. Overdue is a fact the
     * system reports about itself; it cannot be suppressed.
     *
     * @return array<string, string>
     */
    public function disclosureStatus(Incident $incident): array
    {
        $published = IncidentDisclosure::query()
            ->where('incident_id', $incident->id)
            ->get()
            ->keyBy(static fn (IncidentDisclosure $d): string => $d->kind->value);

        $deadlines = [
            DisclosureKind::StatusPage->value => $incident->status_page_due_at,
            DisclosureKind::PreliminaryReport->value => $incident->preliminary_report_due_at,
            DisclosureKind::Postmortem->value => $incident->postmortem_due_at,
        ];

        $status = [];

        foreach ($deadlines as $kind => $dueAt) {
            if ($published->has($kind)) {
                $status[$kind] = 'published';
            } elseif (now()->greaterThan($dueAt)) {
                $status[$kind] = 'OVERDUE';
            } else {
                $status[$kind] = 'pending';
            }
        }

        return $status;
    }
}
