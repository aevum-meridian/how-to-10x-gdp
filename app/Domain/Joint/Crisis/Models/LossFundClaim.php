<?php

/**
 * SPDX-License-Identifier: LicenseRef-MVL-2.0 AND LicenseRef-AVL-2.0
 * DEV-8 / DOCUMENT 8.2 — a public claim against the loss fund. © Maher
 */

declare(strict_types=1);

namespace App\Domain\Joint\Crisis\Models;

use App\Domain\Joint\Crisis\Enums\ClaimCategory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * The boundary lives in the DB: CHECK loss_fund_claims_boundary makes an
 * approved claim in any category but protocol_bug unrepresentable.
 *
 * @property string $id
 * @property string $claimant_account_id
 * @property int $amount_minor
 * @property string $narrative
 * @property string $exclusions_disclosed
 * @property ClaimCategory|null $category
 * @property string $status
 * @property string|null $decision_receipt
 * @property string|null $payout_transaction_id
 * @property \Illuminate\Support\Carbon|null $appealed_at
 * @property string|null $appeal_note
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon|null $decided_at
 */
final class LossFundClaim extends Model
{
    use HasUlids;

    public $timestamps = false;

    protected $table = 'loss_fund_claims';

    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer',
            'category' => ClaimCategory::class,
            'appealed_at' => 'datetime',
            'created_at' => 'datetime',
            'decided_at' => 'datetime',
        ];
    }
}
