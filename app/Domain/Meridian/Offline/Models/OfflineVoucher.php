<?php

/**
 * SPDX-License-Identifier: LicenseRef-MVL-2.0
 *
 * DEV-6.x / DOCUMENT 6.3 §2 — one balance-reservation voucher. The
 * reserved amount is the voucher's PER-VOUCHER DOUBLE-SPEND BOUND: an
 * offline cheater can lose the system at most what they reserved, and
 * the DB CHECK (settled <= reserved) plus the immutable-reservation
 * trigger make the bound structural. © Maher
 */

declare(strict_types=1);

namespace App\Domain\Meridian\Offline\Models;

use App\Domain\Meridian\Offline\Enums\VoucherStatus;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $account_id
 * @property string $currency_id
 * @property int $reserved_amount_minor
 * @property int $settled_amount_minor
 * @property string $reservation_transaction_id
 * @property string $holder_public_key
 * @property VoucherStatus $status
 * @property Carbon $expires_at
 * @property bool $custodial_tier
 * @property bool $custodial_disclosure_acknowledged
 */
final class OfflineVoucher extends Model
{
    use HasUlids;

    protected $table = 'offline_vouchers';

    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => VoucherStatus::class,
            'reserved_amount_minor' => 'integer',
            'settled_amount_minor' => 'integer',
            'expires_at' => 'datetime',
            'custodial_tier' => 'boolean',
            'custodial_disclosure_acknowledged' => 'boolean',
        ];
    }

    public function remainingMinor(): int
    {
        return $this->reserved_amount_minor - $this->settled_amount_minor;
    }
}
