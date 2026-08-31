<?php

/**
 * SPDX-License-Identifier: LicenseRef-MVL-2.0 AND LicenseRef-AVL-2.0
 * DEV-8 / DOCUMENT 8.1 §2 — a published, append-only disclosure. © Maher
 */

declare(strict_types=1);

namespace App\Domain\Joint\Crisis\Models;

use App\Domain\Joint\Crisis\Enums\DisclosureKind;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $incident_id
 * @property DisclosureKind $kind
 * @property string $content
 * @property \Illuminate\Support\Carbon $published_at
 */
final class IncidentDisclosure extends Model
{
    use HasUlids;

    public $timestamps = false;

    protected $table = 'incident_disclosures';

    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'kind' => DisclosureKind::class,
            'published_at' => 'datetime',
        ];
    }
}
