<?php

/**
 * SPDX-License-Identifier: LicenseRef-MVL-2.0 AND LicenseRef-AVL-2.0
 *
 * DEV-6.x / DOCUMENT 6.2 §2 — a parameter amendable ONLY by the
 * constitutional process. The DB trigger refuses any value change that
 * does not carry a fresh amendment reference, and refuses deletion
 * outright: the process must leave a mark. © Maher
 */

declare(strict_types=1);

namespace App\Domain\Identity\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property string $key
 * @property int $value_minor
 * @property non-empty-string $amendment_ref
 */
final class ConstitutionalParameter extends Model
{
    protected $table = 'constitutional_parameters';

    protected $primaryKey = 'key';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'value_minor' => 'integer',
        ];
    }
}
