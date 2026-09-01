<?php

/**
 * SPDX-License-Identifier: LicenseRef-MVL-2.0
 *
 * DEV-6.x / DOCUMENT 6.3 §2 — one offline-signed deferred settlement
 * record, submitted on reconnection. Replay-bounded by the per-voucher
 * unique nonce; an intercepted record cannot be replayed beyond the
 * reservation (DOCUMENT 6.3 §5). © Maher
 */

declare(strict_types=1);

namespace App\Domain\Meridian\Offline\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $voucher_id
 * @property string $payee_account_id
 * @property int $amount_minor
 * @property string $nonce
 * @property string $holder_signature
 * @property string $status
 * @property string|null $settlement_transaction_id
 */
final class DeferredSettlement extends Model
{
    use HasUlids;

    protected $table = 'deferred_settlements';

    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer',
        ];
    }
}
