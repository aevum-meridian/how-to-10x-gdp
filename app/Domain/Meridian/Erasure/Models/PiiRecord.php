<?php

/**
 * SPDX-License-Identifier: LicenseRef-MVL-2.0 AND LicenseRef-AVL-2.0
 * DEV-6.5 — an off-ledger, erasable, encrypted PII record. © Maher
 */

declare(strict_types=1);

namespace App\Domain\Meridian\Erasure\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * The ONLY mapping from an opaque on-ledger reference to a person. Its
 * payload is ciphertext under a per-record key; deleting record + key
 * (crypto-shredding) makes the person unrecoverable while every
 * on-ledger economic fact survives.
 *
 * @property string $id
 * @property string $subject_reference
 * @property string $purpose
 * @property string $ciphertext
 * @property string $key_id
 * @property \Illuminate\Support\Carbon $created_at
 */
final class PiiRecord extends Model
{
    use HasUlids;

    public $timestamps = false;

    protected $table = 'pii_records';

    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
        ];
    }
}
