<?php

/**
 * SPDX-License-Identifier: LicenseRef-MVL-2.0 AND LicenseRef-AVL-2.0
 *
 * DEV-6.x / DOCUMENT 6.2 §2 — the vesting ramp for a Rung-1 identity.
 * Value ramps toward full over vesting_days and RESETS on slash, so a
 * freshly-created Sybil identity cannot immediately extract full value.
 * © Maher
 */

declare(strict_types=1);

namespace App\Domain\Identity\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $identity_id
 * @property Carbon $vesting_started_at
 * @property int $vesting_days
 * @property int $slash_count
 * @property Carbon|null $last_slashed_at
 */
final class AttestationVesting extends Model
{
    use HasUlids;

    protected $table = 'attestation_vestings';

    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'vesting_started_at' => 'datetime',
            'last_slashed_at' => 'datetime',
            'vesting_days' => 'integer',
            'slash_count' => 'integer',
        ];
    }
}
