<?php

/**
 * SPDX-License-Identifier: LicenseRef-MVL-2.0
 * DOCUMENT 4.1 — MERIDIAN LEDGER CORE data model. © Maher
 */

declare(strict_types=1);

namespace App\Domain\Meridian\Ledger\Models;

use App\Domain\Meridian\Ledger\Enums\AccountType;
use App\Domain\Meridian\Ledger\Enums\SystemAccountRole;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string|null $owner_id
 * @property string $owner_type
 * @property string $currency_id
 * @property AccountType $type
 * @property SystemAccountRole|null $system_role
 * @property string $status
 * @property int $balance_minor
 */
final class Account extends Model
{
    use HasUlids;

    public $timestamps = false;

    protected $table = 'accounts';

    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'type' => AccountType::class,
            'system_role' => SystemAccountRole::class,
            'balance_minor' => 'integer',
        ];
    }

    /** @return BelongsTo<Currency, $this> */
    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class);
    }

    public function isPersonal(): bool
    {
        return $this->owner_type === 'person';
    }
}
