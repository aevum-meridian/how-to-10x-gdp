<?php

/**
 * SPDX-License-Identifier: LicenseRef-MVL-2.0 AND LicenseRef-AVL-2.0
 * DEV-8 / DOCUMENT 8.1 — an incident with a bound disclosure clock. © Maher
 */

declare(strict_types=1);

namespace App\Domain\Joint\Crisis\Models;

use App\Domain\Joint\Crisis\Enums\Severity;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * DB triggers make the clock one-way (no deadline ever moves later, the
 * severity is never rewritten) and the record undeletable: the body
 * fails openly or not at all.
 *
 * @property string $id
 * @property Severity $severity
 * @property string $summary
 * @property string $commander_role
 * @property string $status
 * @property string $trigger_source
 * @property \Illuminate\Support\Carbon $declared_at
 * @property \Illuminate\Support\Carbon $status_page_due_at
 * @property \Illuminate\Support\Carbon $preliminary_report_due_at
 * @property \Illuminate\Support\Carbon $postmortem_due_at
 * @property \Illuminate\Support\Carbon|null $closed_at
 */
final class Incident extends Model
{
    use HasUlids;

    public $timestamps = false;

    protected $table = 'incidents';

    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'severity' => Severity::class,
            'declared_at' => 'datetime',
            'status_page_due_at' => 'datetime',
            'preliminary_report_due_at' => 'datetime',
            'postmortem_due_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }
}
