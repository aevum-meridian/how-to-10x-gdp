<?php

/**
 * SPDX-License-Identifier: LicenseRef-MVL-2.0
 * DEV-4.2 — ISSUANCE & PoVC ENGINE data model. © Maher
 */

declare(strict_types=1);

namespace App\Domain\Meridian\Issuance\Models;

use App\Domain\Meridian\Issuance\Enums\BaseKind;
use App\Domain\Meridian\Issuance\Enums\IncreaseKind;
use App\Domain\Meridian\Issuance\Enums\IssuanceType;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $currency_id
 * @property IssuanceType $type
 * @property array<string, mixed> $params
 * @property array<string, mixed>|null $rate_limit
 * @property array<string, mixed>|null $decay_rule
 * @property int|null $max_supply
 * @property BaseKind $base_kind
 * @property IncreaseKind $increase_kind
 * @property bool $risk_bearing
 * @property bool $value_creating
 * @property bool $extracts_from_counterparty
 */
final class IssuancePolicy extends Model
{
    use HasUlids;

    public $timestamps = false;

    protected $table = 'issuance_policies';

    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'type' => IssuanceType::class,
            'params' => 'array',
            'rate_limit' => 'array',
            'decay_rule' => 'array',
            'base_kind' => BaseKind::class,
            'increase_kind' => IncreaseKind::class,
            'risk_bearing' => 'bool',
            'value_creating' => 'bool',
            'extracts_from_counterparty' => 'bool',
        ];
    }
}
