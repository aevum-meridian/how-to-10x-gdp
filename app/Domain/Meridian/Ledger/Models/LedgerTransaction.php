<?php

/**
 * SPDX-License-Identifier: LicenseRef-MVL-2.0
 * DOCUMENT 4.1 — MERIDIAN LEDGER CORE data model. © Maher
 */

declare(strict_types=1);

namespace App\Domain\Meridian\Ledger\Models;

use App\Domain\Meridian\Ledger\Enums\TransactionKind;
use App\Domain\Meridian\Ledger\Enums\TransactionStatus;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $id
 * @property TransactionKind $kind
 * @property TransactionStatus $status
 * @property string|null $reverses_transaction_id
 * @property string|null $reverses_mint_transaction_id
 * @property string|null $arbitration_case_id
 * @property string $idempotency_key
 */
final class LedgerTransaction extends Model
{
    use HasUlids;

    public $timestamps = false;

    protected $table = 'transactions';

    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'kind' => TransactionKind::class,
            'status' => TransactionStatus::class,
            'metadata' => 'array',
        ];
    }

    /** @return HasMany<Entry, $this> */
    public function entries(): HasMany
    {
        return $this->hasMany(Entry::class, 'transaction_id');
    }
}
