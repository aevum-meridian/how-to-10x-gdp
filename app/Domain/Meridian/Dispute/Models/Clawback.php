<?php

/**
 * SPDX-License-Identifier: LicenseRef-MVL-2.0
 *
 * DEV-4.3 — a clawback applied on a fraud ruling. The target enum (and
 * the mirroring DB CHECK) admits attester_bond, issuer_bond, or
 * specific_fraudulent_mint — never a generic personal account. © Maher
 */

declare(strict_types=1);

namespace App\Domain\Meridian\Dispute\Models;

use App\Domain\Meridian\Dispute\Enums\ClawbackTarget;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $dispute_id
 * @property ClawbackTarget $target
 * @property int $amount
 * @property string|null $applied_transaction_id
 */
final class Clawback extends Model
{
    use HasUlids;

    public $timestamps = false;

    protected $table = 'clawbacks';

    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'target' => ClawbackTarget::class,
        ];
    }
}
