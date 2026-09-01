<?php

/**
 * SPDX-License-Identifier: LicenseRef-MVL-2.0
 * DEV-4.5 — per-credit Goodhart observation (DOCUMENT 2.3). © Maher
 */

declare(strict_types=1);

namespace App\Domain\Meridian\Policy\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $currency_id
 * @property string $declared_virtue
 * @property numeric-string $measured_proxy
 * @property numeric-string $independent_signal
 * @property numeric-string $divergence
 * @property numeric-string $threshold
 * @property numeric-string $throttle_value
 * @property \Illuminate\Support\Carbon|null $last_evaluated
 */
final class ProxyMetric extends Model
{
    use HasUlids;

    public $timestamps = false;

    protected $table = 'proxy_metrics';

    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'last_evaluated' => 'datetime',
        ];
    }
}
