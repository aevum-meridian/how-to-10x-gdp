<?php

/**
 * SPDX-License-Identifier: LicenseRef-MVL-2.0 AND LicenseRef-AVL-2.0
 * DEV-6.5 — proof a fact occurred, with no path to who. © Maher
 */

declare(strict_types=1);

namespace App\Domain\Meridian\Erasure\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * Append-only by DB trigger (trg_erasure_tombstones_immutable). The
 * subject_digest is a one-way hash — the tombstone proves "an account
 * transacted here" without any path back to the person.
 *
 * @property string $id
 * @property string $pii_record_id
 * @property string $subject_digest
 * @property string $reason
 * @property \Illuminate\Support\Carbon $shredded_at
 */
final class ErasureTombstone extends Model
{
    use HasUlids;

    public $timestamps = false;

    protected $table = 'erasure_tombstones';

    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'shredded_at' => 'datetime',
        ];
    }
}
