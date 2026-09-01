<?php

/**
 * SPDX-License-Identifier: LicenseRef-MVL-2.0 AND LicenseRef-AVL-2.0
 *
 * DEV-6.x / DOCUMENT 6.2 §3 — one timelocked, contestable social
 * recovery attempt. The DB trigger recovery_guard_completion() refuses
 * completion before the window elapses, below the M-of-N threshold,
 * while contested, without a decision receipt, or without elevated
 * monitoring. Deliberately no email field: recovery is never an email
 * reset. © Maher
 */

declare(strict_types=1);

namespace App\Domain\Identity\Models;

use App\Domain\Identity\Enums\RecoveryStatus;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $guardian_set_id
 * @property string $new_key_commitment
 * @property RecoveryStatus $status
 * @property Carbon $initiated_at
 * @property Carbon $challenge_window_ends_at
 * @property list<array{guardian_key: string, signature: string}> $guardian_approvals
 * @property Carbon|null $contested_at
 * @property Carbon|null $completed_at
 * @property string|null $decision_receipt
 * @property bool $elevated_monitoring
 */
final class RecoveryAttempt extends Model
{
    use HasUlids;

    protected $table = 'recovery_attempts';

    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => RecoveryStatus::class,
            'initiated_at' => 'datetime',
            'challenge_window_ends_at' => 'datetime',
            'contested_at' => 'datetime',
            'completed_at' => 'datetime',
            'guardian_approvals' => 'array',
            'elevated_monitoring' => 'boolean',
        ];
    }
}
