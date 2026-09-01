<?php

/**
 * SPDX-License-Identifier: LicenseRef-AVL-2.0
 *
 * DEV-4.4 — user-sovereign filter rules (DOCUMENT 0.5 Face 1: "each
 * wallet sets its own rules about what assets it will hold, surface,
 * or route"). © Maher
 */

declare(strict_types=1);

namespace App\Domain\Aevum\Fabric\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $user_ref
 * @property array<string, mixed> $filter_rules
 * @property Carbon $updated_at
 */
final class UserClientPreference extends Model
{
    use HasUlids;

    public $timestamps = false;

    protected $table = 'user_client_preferences';

    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'filter_rules' => 'array',
            'updated_at' => 'datetime',
        ];
    }
}
