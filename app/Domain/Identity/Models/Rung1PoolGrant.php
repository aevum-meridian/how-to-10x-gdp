<?php

/**
 * SPDX-License-Identifier: LicenseRef-MVL-2.0 AND LicenseRef-AVL-2.0
 *
 * DEV-6.x / DOCUMENT 6.2 §2 — one grant from the probationary Rung-1
 * pool. Append-only bookkeeping against the hard constitutional cap:
 * the DB trigger refuses any grant that would exceed the cap and any
 * mutation that would un-spend the budget. © Maher
 */

declare(strict_types=1);

namespace App\Domain\Identity\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $identity_id
 * @property int $amount_minor
 * @property string $idempotency_key
 * @property Carbon $granted_at
 */
final class Rung1PoolGrant extends Model
{
    use HasUlids;

    public $timestamps = false;

    protected $table = 'rung1_pool_grants';

    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer',
            'granted_at' => 'datetime',
        ];
    }
}
