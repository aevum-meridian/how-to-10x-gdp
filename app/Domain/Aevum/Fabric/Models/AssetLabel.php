<?php

/**
 * SPDX-License-Identifier: LicenseRef-AVL-2.0
 *
 * DEV-4.4 — a multi-sourced, contestable asset label with provenance.
 * Labels inform the user-sovereign filter; they are never verdicts
 * (DOCUMENT 0.5: "multi-sourced, contestable labels rather than
 * imposing a single verdict"). © Maher
 */

declare(strict_types=1);

namespace App\Domain\Aevum\Fabric\Models;

use App\Domain\Aevum\Fabric\Enums\LabelCategory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $asset_ref
 * @property LabelCategory $category
 * @property string $source
 * @property string $provenance
 * @property string $contestation_status
 * @property Carbon $labeled_at
 */
final class AssetLabel extends Model
{
    use HasUlids;

    public $timestamps = false;

    protected $table = 'asset_labels';

    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'category' => LabelCategory::class,
            'labeled_at' => 'datetime',
        ];
    }
}
