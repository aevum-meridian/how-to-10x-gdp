<?php

/**
 * SPDX-License-Identifier: LicenseRef-MVL-2.0 AND LicenseRef-AVL-2.0
 *
 * DEV-6.x / DOCUMENT 6.2 §3 — a user's chosen M-of-N recovery guardians.
 * The threshold is DB-CHECKed at >= 2: recovery can never collapse to a
 * single party's say-so. © Maher
 */

declare(strict_types=1);

namespace App\Domain\Identity\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $identity_id
 * @property list<string> $guardian_public_keys
 * @property int $threshold
 */
final class GuardianSet extends Model
{
    use HasUlids;

    protected $table = 'guardian_sets';

    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'guardian_public_keys' => 'array',
            'threshold' => 'integer',
        ];
    }
}
