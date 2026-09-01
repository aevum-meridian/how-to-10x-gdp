<?php

/**
 * SPDX-License-Identifier: LicenseRef-AVL-2.0
 * DEV-4.4 — a registered pluggable currency experience. © Maher
 */

declare(strict_types=1);

namespace App\Domain\Aevum\Fabric\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $currency_id
 * @property array<string, mixed> $presentation
 * @property bool $core_riba_checked
 * @property Carbon $registered_at
 */
final class ExperienceSpec extends Model
{
    use HasUlids;

    public $timestamps = false;

    protected $table = 'experience_specs';

    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'presentation' => 'array',
            'core_riba_checked' => 'bool',
            'registered_at' => 'datetime',
        ];
    }
}
