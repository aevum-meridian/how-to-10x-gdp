<?php

/**
 * SPDX-License-Identifier: LicenseRef-MVL-2.0 AND LicenseRef-AVL-2.0
 * DEV-6.5 — the per-record key whose destruction IS the erasure. © Maher
 */

declare(strict_types=1);

namespace App\Domain\Meridian\Erasure\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $key_material
 * @property \Illuminate\Support\Carbon $created_at
 */
final class PiiEncryptionKey extends Model
{
    use HasUlids;

    public $timestamps = false;

    protected $table = 'pii_encryption_keys';

    protected $guarded = [];

    /** Key material must never leak into logs or serialized output. */
    protected $hidden = ['key_material'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
        ];
    }
}
