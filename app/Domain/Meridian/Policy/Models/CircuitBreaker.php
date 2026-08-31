<?php

/**
 * SPDX-License-Identifier: LicenseRef-MVL-2.0
 * DEV-4.5 — a protective halt on automatic issuance/conversion. © Maher
 */

declare(strict_types=1);

namespace App\Domain\Meridian\Policy\Models;

use App\Domain\Meridian\Policy\Enums\BreakerReason;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $currency_id
 * @property string $status
 * @property BreakerReason $reason
 * @property \Illuminate\Support\Carbon $fired_at
 * @property \Illuminate\Support\Carbon|null $cleared_at
 */
final class CircuitBreaker extends Model
{
    use HasUlids;

    public $timestamps = false;

    protected $table = 'circuit_breakers';

    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'reason' => BreakerReason::class,
            'fired_at' => 'datetime',
            'cleared_at' => 'datetime',
        ];
    }
}
