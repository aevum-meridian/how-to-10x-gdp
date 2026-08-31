<?php

/**
 * SPDX-License-Identifier: LicenseRef-AVL-2.0
 *
 * DEV-4.4 / A-§C.10 — the membrane's experience face. evaluate()
 * returns admit/warn/refuse for the experience edge, user-sovereign by
 * default:
 *
 *  - the user's OWN rules govern admit/warn/refuse for their wallet,
 *    and a user-rule refusal is theirs to change (overridable BY the
 *    user, imposed on no one else);
 *  - an ACTIVE constitutional global block is the one non-overridable
 *    refusal — and it can only have become active through timelock +
 *    public justification + closed appeal (the DB trigger guarantees
 *    this; see GlobalBlockList).
 *
 * The inversion (DOCUMENT 0.5): the filter refuses, but it cannot
 * reach. It has no capability to move, mint, or debit anything —
 * its whole output type is a verdict. A-§C.14 and I10 hold: value
 * moves only when a holder authorizes and the system of record
 * validates.
 *
 * © Maher
 */

declare(strict_types=1);

namespace App\Domain\Aevum\Fabric\Services;

use App\Domain\Aevum\Fabric\Data\Asset;
use App\Domain\Aevum\Fabric\Data\FilterResult;
use App\Domain\Aevum\Fabric\Data\UserRules;
use App\Domain\Aevum\Fabric\Enums\FilterVerdict;
use App\Domain\Aevum\Fabric\Models\GlobalBlock;

final class EthicalFilter
{
    public function evaluate(Asset $asset, UserRules $rules): FilterResult
    {
        // 1. Constitutional global block — the only mandatory,
        //    non-user-overridable refusal at this face. Only rows the
        //    constitutional trigger allowed into 'active' count.
        $activeBlock = GlobalBlock::query()
            ->where('asset_ref', $asset->assetRef)
            ->where('status', 'active')
            ->first();

        if ($activeBlock !== null) {
            return new FilterResult(
                verdict: FilterVerdict::Refuse,
                reason: 'Constitutional global block (timelocked, publicly justified, '
                    ."appealable): {$activeBlock->justification}",
                matchedCategories: [],
                userOverridable: false,
            );
        }

        // 2. The user's own refuse rules — sovereign, and theirs alone.
        $refused = array_values(array_intersect($rules->refuseCategories, $asset->labelCategories));

        if ($refused !== []) {
            return new FilterResult(
                verdict: FilterVerdict::Refuse,
                reason: 'Refused by your own wallet rules for: '.implode(', ', $refused)
                    .'. You may change these rules at any time.',
                matchedCategories: $refused,
                userOverridable: true,
            );
        }

        // 3. Warn categories — flag, inform, and let the user decide
        //    (DOCUMENT 0.5: "the membrane's refusal is informative").
        $warned = array_values(array_intersect($rules->warnCategories, $asset->labelCategories));

        if ($warned !== []) {
            return new FilterResult(
                verdict: FilterVerdict::Warn,
                reason: 'Flagged by your wallet rules for: '.implode(', ', $warned)
                    .'. The decision is yours.',
                matchedCategories: $warned,
                userOverridable: true,
            );
        }

        return new FilterResult(
            verdict: FilterVerdict::Admit,
            reason: 'No user rule or constitutional block applies.',
            matchedCategories: [],
            userOverridable: true,
        );
    }
}
