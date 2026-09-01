<?php

/**
 * SPDX-License-Identifier: LicenseRef-MVL-2.0
 * DEV-4.5 — every Policy Engine action, versioned to the transparency
 * log. © Maher
 */

declare(strict_types=1);

namespace App\Domain\Meridian\Policy\Models;

use App\Domain\Meridian\Policy\Enums\PolicyActionType;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $currency_id
 * @property PolicyActionType $action_type
 * @property array<string, mixed> $delta
 * @property string $justification
 * @property string $transparency_log_ref
 */
final class PolicyAction extends Model
{
    use HasUlids;

    public $timestamps = false;

    protected $table = 'policy_actions';

    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'action_type' => PolicyActionType::class,
            'delta' => 'array',
        ];
    }
}
