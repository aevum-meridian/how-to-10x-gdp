<?php

/**
 * SPDX-License-Identifier: LicenseRef-AVL-2.0
 *
 * DEV-4.4 / A-§C.10 — a constitutional global block. The one membrane
 * power that overrides individual user sovereignty, so it is the one
 * that is constitutionally gated: it can only ENTER the timelocked,
 * publicly-justified, appealable process (never apply directly); the DB
 * trigger global_blocks_guard_activation() rejects any birth or
 * transition that skips the process. © Maher
 */

declare(strict_types=1);

namespace App\Domain\Aevum\Fabric\Models;

use App\Domain\Aevum\Fabric\Enums\BlockStatus;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $asset_ref
 * @property non-empty-string $justification
 * @property Carbon $proposed_at
 * @property Carbon $timelock_until
 * @property string $appeal_status
 * @property BlockStatus $status
 * @property string $transparency_log_ref
 */
final class GlobalBlock extends Model
{
    use HasUlids;

    public $timestamps = false;

    protected $table = 'global_blocks';

    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => BlockStatus::class,
            'proposed_at' => 'datetime',
            'timelock_until' => 'datetime',
        ];
    }
}
