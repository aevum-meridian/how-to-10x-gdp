<?php

/**
 * SPDX-License-Identifier: LicenseRef-AVL-2.0
 *
 * DEV-4.4 / A-§C.10 — the global-block service. propose() can ONLY
 * enter the timelocked, publicly-justified, appealable constitutional
 * process — never apply a block directly. There is deliberately no
 * method on this class that sets a block active in one step: activation
 * is a separate, later act (activate()) that the DB trigger
 * global_blocks_guard_activation() re-verifies independently —
 * timelock elapsed, justification present, no open or upheld appeal.
 *
 * © Maher
 */

declare(strict_types=1);

namespace App\Domain\Aevum\Fabric\Services;

use App\Domain\Aevum\Fabric\Enums\BlockStatus;
use App\Domain\Aevum\Fabric\Exceptions\ConstitutionalProcessException;
use App\Domain\Aevum\Fabric\Models\GlobalBlock;
use Illuminate\Support\Carbon;

final class GlobalBlockList
{
    /** The constitutional minimum timelock (days) before activation. */
    private const MIN_TIMELOCK_DAYS = 30;

    /**
     * Enter a block into the constitutional process. It is born
     * 'proposed', with a public written justification, a timelock of
     * at least the constitutional minimum, and a transparency-log
     * reference. It applies to no one yet.
     */
    public function propose(
        string $assetRef,
        string $justification,
        Carbon $timelockUntil,
        string $transparencyLogRef,
    ): GlobalBlock {
        if (trim($justification) === '') {
            throw new ConstitutionalProcessException(
                'A-§C.10: a global block requires a public written justification; '
                .'a silent block is a breach.'
            );
        }

        if ($timelockUntil->lessThan(now()->addDays(self::MIN_TIMELOCK_DAYS))) {
            throw new ConstitutionalProcessException(
                'A-§C.10: the timelock must run at least '.self::MIN_TIMELOCK_DAYS
                .' days; a block that activates immediately is unilateral in effect.'
            );
        }

        return GlobalBlock::query()->create([
            'asset_ref' => $assetRef,
            'justification' => $justification,
            'proposed_at' => now(),
            'timelock_until' => $timelockUntil,
            'appeal_status' => 'none',
            'status' => BlockStatus::Proposed,
            'transparency_log_ref' => $transparencyLogRef,
        ]);
    }

    /**
     * Open an appeal against a proposed block. While the appeal is
     * open, activation is impossible (service AND trigger); if upheld,
     * the block is void forever.
     */
    public function appeal(GlobalBlock $block): GlobalBlock
    {
        if ($block->status !== BlockStatus::Proposed) {
            throw new ConstitutionalProcessException(
                'A-§C.10: only a proposed block can be appealed at this face.'
            );
        }

        $block->appeal_status = 'open';
        $block->status = BlockStatus::Appealed;
        $block->save();

        return $block->refresh();
    }

    /**
     * Attempt activation — a distinct, later act. The service checks
     * the constitutional conditions and the DB trigger re-verifies
     * every one of them independently: even a compromised service
     * cannot activate a block early, silently, or over an appeal.
     */
    public function activate(GlobalBlock $block): GlobalBlock
    {
        if ($block->status !== BlockStatus::Proposed) {
            throw new ConstitutionalProcessException(
                "A-§C.10: only a proposed block may activate; this one is {$block->status->value}."
            );
        }

        if ($block->timelock_until->greaterThan(now())) {
            throw new ConstitutionalProcessException(
                'A-§C.10: the timelock has not elapsed; activation refused.'
            );
        }

        if ($block->appeal_status === 'open') {
            throw new ConstitutionalProcessException(
                'A-§C.10: an appeal is open; activation refused until it closes.'
            );
        }

        $block->status = BlockStatus::Active;
        $block->save();

        return $block->refresh();
    }
}
