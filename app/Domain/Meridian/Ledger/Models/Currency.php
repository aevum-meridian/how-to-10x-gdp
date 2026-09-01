<?php

/**
 * SPDX-License-Identifier: LicenseRef-MVL-2.0
 * DOCUMENT 4.1 — MERIDIAN LEDGER CORE data model. © Maher
 */

declare(strict_types=1);

namespace App\Domain\Meridian\Ledger\Models;

use App\Domain\Meridian\Ledger\Enums\CurrencyFamily;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property non-empty-string $code
 * @property non-empty-string $name
 * @property CurrencyFamily $family
 * @property int<0, max> $decimals
 * @property string|null $issuance_policy_id
 * @property bool $is_transferable
 * @property bool $is_active
 */
final class Currency extends Model
{
    use HasUlids;

    public $timestamps = false;

    protected $table = 'currencies';

    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'family' => CurrencyFamily::class,
            'decimals' => 'integer',
            'is_transferable' => 'boolean',
            'is_active' => 'boolean',
            'governance_metadata' => 'array',
        ];
    }
}
