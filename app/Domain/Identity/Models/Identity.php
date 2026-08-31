<?php

/**
 * SPDX-License-Identifier: LicenseRef-MVL-2.0 AND LicenseRef-AVL-2.0
 *
 * DEV-6.x / DOCUMENT 6.1 §3 — the persisted multi-provider aggregation
 * record. Holds ONLY the opaque subject commitment, the versioned
 * aggregation outcome, and the explainable/appealable determination —
 * never raw PII (I8). © Maher
 */

declare(strict_types=1);

namespace App\Domain\Identity\Models;

use App\Domain\Identity\Enums\AppealStatus;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $subject_commitment
 * @property string $aggregation_version
 * @property int $effective_rung
 * @property array<int, array<string, mixed>> $provider_attestations
 * @property AppealStatus $appeal_status
 * @property numeric-string $sybil_risk_score
 * @property string $explanation
 */
final class Identity extends Model
{
    use HasUlids;

    protected $table = 'identities';

    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'provider_attestations' => 'array',
            'appeal_status' => AppealStatus::class,
            'effective_rung' => 'integer',
        ];
    }
}
