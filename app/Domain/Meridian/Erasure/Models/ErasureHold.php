<?php

/**
 * SPDX-License-Identifier: LicenseRef-MVL-2.0 AND LicenseRef-AVL-2.0
 * DEV-6.5 — a bounded, disclosed legal hold, never indefinite. © Maher
 */

declare(strict_types=1);

namespace App\Domain\Meridian\Erasure\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $pii_record_id
 * @property string $dispute_id
 * @property string $disclosed_reason
 * @property \Illuminate\Support\Carbon $hold_expires_at
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon|null $released_at
 */
final class ErasureHold extends Model
{
    use HasUlids;

    public $timestamps = false;

    protected $table = 'erasure_holds';

    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'hold_expires_at' => 'datetime',
            'created_at' => 'datetime',
            'released_at' => 'datetime',
        ];
    }
}
