<?php

/**
 * SPDX-License-Identifier: LicenseRef-MVL-2.0 AND LicenseRef-AVL-2.0
 *
 * DEV-6.x / DOCUMENT 6.2 §2 — one Sybil-cluster bounty report.
 * Evidence is graph-structural, targeting clusters; the reporter is an
 * opaque commitment (pseudonymous reporting). © Maher
 */

declare(strict_types=1);

namespace App\Domain\Identity\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $reporter_commitment
 * @property array<string, mixed> $cluster_evidence
 * @property string $status
 * @property string|null $resolution_note
 */
final class SybilBounty extends Model
{
    use HasUlids;

    protected $table = 'sybil_bounties';

    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'cluster_evidence' => 'array',
        ];
    }
}
