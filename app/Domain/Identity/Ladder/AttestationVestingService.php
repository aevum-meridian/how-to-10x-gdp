<?php

/**
 * SPDX-License-Identifier: LicenseRef-MVL-2.0 AND LicenseRef-AVL-2.0
 *
 * DEV-6.x / DOCUMENT 6.2 §2 — the vesting ramp. A new identity's
 * attestation value ramps linearly toward 1.0 over vesting_days and
 * RESETS on slash: a freshly-created Sybil identity cannot immediately
 * extract full value, and a slashed one starts over. The multiplier is
 * pure bookkeeping over timestamps — it never touches a balance.
 * © Maher
 */

declare(strict_types=1);

namespace App\Domain\Identity\Ladder;

use App\Domain\Identity\Models\AttestationVesting;
use App\Domain\Identity\Models\Identity;
use Illuminate\Support\Carbon;

final class AttestationVestingService
{
    public const DEFAULT_VESTING_DAYS = 90;

    public function startVesting(Identity $identity, int $vestingDays = self::DEFAULT_VESTING_DAYS): AttestationVesting
    {
        if ($vestingDays < 1) {
            throw new \InvalidArgumentException('VESTING: the ramp must span at least one day.');
        }

        $existing = AttestationVesting::query()->where('identity_id', $identity->id)->first();

        if ($existing !== null) {
            return $existing;
        }

        $vesting = new AttestationVesting([
            'identity_id' => $identity->id,
            'vesting_started_at' => now(),
            'vesting_days' => $vestingDays,
        ]);
        $vesting->save();

        return $vesting;
    }

    /**
     * The vested multiplier in [0, 1]: linear ramp from the (re)start.
     */
    public function vestedMultiplier(AttestationVesting $vesting, ?Carbon $at = null): float
    {
        $at ??= Carbon::now();

        $elapsedDays = $vesting->vesting_started_at->diffInSeconds($at) / 86_400.0;

        if ($elapsedDays <= 0.0) {
            return 0.0;
        }

        return min(1.0, $elapsedDays / (float) $vesting->vesting_days);
    }

    /**
     * Slash: the ramp RESETS — the identity re-earns trust from zero.
     * This touches only the vesting record, never any balance (I6: a
     * slash here reduces future entitlement, not existing credits).
     */
    public function slash(AttestationVesting $vesting): AttestationVesting
    {
        $vesting->vesting_started_at = Carbon::now();
        $vesting->slash_count = $vesting->slash_count + 1;
        $vesting->last_slashed_at = Carbon::now();
        $vesting->save();

        return $vesting;
    }
}
