<?php

/**
 * SPDX-License-Identifier: LicenseRef-MVL-2.0 AND LicenseRef-AVL-2.0
 * DEV-7.1 — a leg's registered Ed25519 verification key. © Maher
 */

declare(strict_types=1);

namespace App\Domain\Joint\EventContract\Models;

use App\Domain\Joint\EventContract\Enums\EventSource;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property EventSource $source
 * @property non-empty-string $public_key
 * @property string $status
 * @property Carbon $registered_at
 */
final class EventSigner extends Model
{
    use HasUlids;

    public $timestamps = false;

    protected $table = 'event_signers';

    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'source' => EventSource::class,
            'registered_at' => 'datetime',
        ];
    }
}
