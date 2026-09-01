<?php

/**
 * SPDX-License-Identifier: LicenseRef-MVL-2.0
 * DEV-8.3 — a licensed custodian registered to attest reserves. © Maher
 */

declare(strict_types=1);

namespace App\Domain\Meridian\Reserve\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $currency_id
 * @property string $name
 * @property string $public_key
 * @property string $license_ref
 * @property \Illuminate\Support\Carbon|null $revoked_at
 * @property \Illuminate\Support\Carbon $created_at
 */
final class ReserveCustodian extends Model
{
    use HasUlids;

    public $timestamps = false;

    protected $table = 'reserve_custodians';

    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'revoked_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }
}
