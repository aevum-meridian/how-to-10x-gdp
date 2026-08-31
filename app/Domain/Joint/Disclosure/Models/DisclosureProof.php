<?php

/**
 * SPDX-License-Identifier: LicenseRef-MVL-2.0 AND LicenseRef-AVL-2.0
 *
 * DEV-6.x / DOCUMENT 6.4 §2 — one minimized-disclosure proof record.
 * The protocol stores the STATEMENT (the public criterion), the
 * COMMITMENT, and the PROOF — never the witness. There is no witness
 * property on this model because there is no witness column in the
 * schema: the absence is the guarantee (I8). © Maher
 */

declare(strict_types=1);

namespace App\Domain\Joint\Disclosure\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $statement
 * @property string $subject_commitment
 * @property string $proof_blob
 * @property string $proof_system
 * @property bool $verified
 * @property bool $consent_revoked
 * @property Carbon|null $expires_at
 */
final class DisclosureProof extends Model
{
    use HasUlids;

    protected $table = 'disclosure_proofs';

    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'verified' => 'boolean',
            'consent_revoked' => 'boolean',
            'expires_at' => 'datetime',
        ];
    }
}
