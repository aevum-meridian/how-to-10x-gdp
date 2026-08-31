<?php

/**
 * SPDX-License-Identifier: LicenseRef-MVL-2.0 AND LicenseRef-AVL-2.0
 *
 * DEV-7.1 — one link in the signed, hash-chained, idempotent stream.
 * The chained content (everything the entry_hash covers) is immutable
 * at the DB layer; only the processing outcome advances. © Maher
 */

declare(strict_types=1);

namespace App\Domain\Joint\EventContract\Models;

use App\Domain\Joint\EventContract\Enums\EventKind;
use App\Domain\Joint\EventContract\Enums\EventSource;
use App\Domain\Joint\EventContract\Enums\EventStatus;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property int $seq
 * @property EventSource $source
 * @property EventKind $kind
 * @property array<string, mixed> $payload
 * @property non-empty-string $canonical_payload
 * @property non-empty-string $prev_hash
 * @property non-empty-string $entry_hash
 * @property non-empty-string $signature
 * @property non-empty-string $idempotency_key
 * @property EventStatus $status
 * @property string|null $result_transaction_id
 * @property string|null $rejection_reason
 * @property Carbon $created_at
 */
final class CrossSystemEvent extends Model
{
    use HasUlids;

    public $timestamps = false;

    protected $table = 'cross_system_events';

    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'source' => EventSource::class,
            'kind' => EventKind::class,
            'status' => EventStatus::class,
            'payload' => 'array',
            'seq' => 'int',
            'created_at' => 'datetime',
        ];
    }

    /** The exact string the entry hash covers — mirrored by the DB trigger. */
    public function hashablePayload(): string
    {
        return implode('|', [
            $this->id,
            $this->source->value,
            $this->kind->value,
            $this->canonical_payload,
            $this->idempotency_key,
            $this->prev_hash,
        ]);
    }
}
