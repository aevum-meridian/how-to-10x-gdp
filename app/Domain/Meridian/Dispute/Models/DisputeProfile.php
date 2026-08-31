<?php

/**
 * SPDX-License-Identifier: LicenseRef-MVL-2.0
 * DEV-4.3 — per-currency dispute configuration. © Maher
 */

declare(strict_types=1);

namespace App\Domain\Meridian\Dispute\Models;

use App\Domain\Meridian\Dispute\Enums\SettlementMode;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $currency_id
 * @property int $window_seconds
 * @property list<int> $bond_schedule Rising bond amounts per round.
 * @property SettlementMode $settlement_mode
 */
final class DisputeProfile extends Model
{
    use HasUlids;

    public $timestamps = false;

    protected $table = 'dispute_profiles';

    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'bond_schedule' => 'array',
            'settlement_mode' => SettlementMode::class,
        ];
    }
}
