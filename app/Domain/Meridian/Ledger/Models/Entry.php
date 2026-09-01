<?php

/**
 * SPDX-License-Identifier: LicenseRef-MVL-2.0
 * DOCUMENT 4.1 — MERIDIAN LEDGER CORE data model. Append-only (I5): this
 * model exposes no update/delete path that the database would honor —
 * the trg_entries_append_only trigger raises on any mutation attempt.
 * © Maher
 */

declare(strict_types=1);

namespace App\Domain\Meridian\Ledger\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property string $transaction_id
 * @property string $account_id
 * @property string $currency_id
 * @property int $amount
 * @property int $balance_after
 * @property string|null $holder_authorization_ref
 * @property int|null $reverses_entry_id
 */
final class Entry extends Model
{
    public $timestamps = false;

    protected $table = 'entries';

    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'balance_after' => 'integer',
        ];
    }

    /** @return BelongsTo<Account, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /** @return BelongsTo<LedgerTransaction, $this> */
    public function transaction(): BelongsTo
    {
        return $this->belongsTo(LedgerTransaction::class, 'transaction_id');
    }
}
