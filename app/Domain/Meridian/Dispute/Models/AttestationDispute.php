<?php

/**
 * SPDX-License-Identifier: LicenseRef-MVL-2.0
 *
 * DEV-4.3 — a dispute against a SPECIFIC provisional mint, never against
 * a person. The closed case (status resolved_fraud + case_closed_at) is
 * one of the two references the I6-revised predicate requires. © Maher
 */

declare(strict_types=1);

namespace App\Domain\Meridian\Dispute\Models;

use App\Domain\Meridian\Dispute\Enums\DisputeStatus;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property non-empty-string $id
 * @property non-empty-string $attestation_id
 * @property non-empty-string $mint_transaction_id
 * @property int $round
 * @property int $bond
 * @property string $challenger_id
 * @property DisputeStatus $status
 * @property array<string, mixed>|null $resolution
 * @property \Illuminate\Support\Carbon|null $case_closed_at
 */
final class AttestationDispute extends Model
{
    use HasUlids;

    public $timestamps = false;

    protected $table = 'attestation_disputes';

    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => DisputeStatus::class,
            'resolution' => 'array',
            'case_closed_at' => 'datetime',
        ];
    }
}
