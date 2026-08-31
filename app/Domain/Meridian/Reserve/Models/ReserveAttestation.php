<?php

/**
 * SPDX-License-Identifier: LicenseRef-MVL-2.0
 * DEV-8.3 — one signed, immutable statement of reserves held. © Maher
 */

declare(strict_types=1);

namespace App\Domain\Meridian\Reserve\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * Append-only by DB trigger (trg_reserve_attestations_immutable): a
 * custodian corrects itself only by attesting again, fresher — never by
 * rewriting the record a user may already have verified against.
 *
 * @property string $id
 * @property string $custodian_id
 * @property string $currency_id
 * @property int $attested_reserve_minor
 * @property string $nonce
 * @property string $signature
 * @property \Illuminate\Support\Carbon $attested_at
 * @property \Illuminate\Support\Carbon $created_at
 */
final class ReserveAttestation extends Model
{
    use HasUlids;

    public $timestamps = false;

    protected $table = 'reserve_attestations';

    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'attested_reserve_minor' => 'integer',
            'attested_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }
}
