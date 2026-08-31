<?php

/**
 * SPDX-License-Identifier: LicenseRef-MVL-2.0 AND LicenseRef-AVL-2.0
 *
 * DEV-8 / DOCUMENT 8.2 — The Protocol-Loss Fund Charter. © Maher
 *
 * The fund covers PROTOCOL BUGS ONLY — explicitly not market risk, not
 * user error, not disclosed experimental risk. The boundary is stated up
 * front (a claim cannot even be SUBMITTED without the exclusions text
 * being disclosed to the claimant — DB CHECK), decided with a public
 * receipt (DB CHECK), appealable, and defended structurally: the DB's
 * loss_fund_claims_boundary CHECK makes approving any other category
 * impossible for anyone, under any pressure. Expanding coverage is a
 * constitutional-grade matter, not a sympathetic reviewer's keystroke.
 *
 * This module holds NO ledger-write power (same charter as the crisis
 * commander): a payout is posted by the treasury through the ordinary
 * guarded ledger path, and recordPayout() merely VERIFIES — by reading
 * the posted entries — that the transaction pays the approved claimant
 * the approved amount before attaching it to the claim.
 */

declare(strict_types=1);

namespace App\Domain\Joint\Crisis\Services;

use App\Domain\Joint\Crisis\Enums\ClaimCategory;
use App\Domain\Joint\Crisis\Exceptions\FundBoundaryException;
use App\Domain\Joint\Crisis\Models\LossFundClaim;
use Illuminate\Support\Facades\DB;

final class LossFundService
{
    /**
     * The exclusions, stated UP FRONT with no false assurance
     * (M-§C.15): every claimant sees this text at submission.
     */
    public const EXCLUSIONS_STATEMENT =
        'The Protocol-Loss Fund covers losses caused by protocol-level defects only. '
        .'It explicitly does NOT cover: market risk (the value of a held asset falling), '
        .'user error (a wrong address, keys lost without social recovery, phishing outside '
        .'any protocol defect), or disclosed experimental risk (losses on instruments whose '
        .'risk was disclosed and accepted at acquisition). This boundary protects the sustainability '
        .'of the fund for all users and is constitutional-grade: it cannot be quietly expanded.';

    /**
     * Submit a claim. The exclusions are disclosed AT submission — a
     * claimant can never claim surprise.
     */
    public function submit(string $claimantAccountId, int $amountMinor, string $narrative): LossFundClaim
    {
        if ($amountMinor <= 0) {
            throw new FundBoundaryException('A claim must name a positive loss.');
        }

        if (trim($narrative) === '') {
            throw new FundBoundaryException(
                'A claim must describe the loss and the protocol defect alleged to have caused it.'
            );
        }

        $claim = new LossFundClaim([
            'claimant_account_id' => $claimantAccountId,
            'amount_minor' => $amountMinor,
            'narrative' => $narrative,
            'exclusions_disclosed' => self::EXCLUSIONS_STATEMENT,
            'status' => 'submitted',
        ]);
        $claim->save();

        return $claim;
    }

    /**
     * Decide a claim. Approval is possible for ClaimCategory::ProtocolBug
     * ONLY — every other category is refused here AND unrepresentable at
     * the DB (loss_fund_claims_boundary). Every decision carries a
     * public receipt.
     */
    public function decide(LossFundClaim $claim, ClaimCategory $category, bool $approve, string $decisionReceipt): LossFundClaim
    {
        if (! in_array($claim->status, ['submitted', 'appealed'], true)) {
            throw new FundBoundaryException(
                "Claim {$claim->id} is already decided; a paid decision is history, an unpaid one is appealed, not re-decided ad hoc."
            );
        }

        if (trim($decisionReceipt) === '') {
            throw new FundBoundaryException(
                'A decision without a public receipt is a verdict without a reason. Refused.'
            );
        }

        if ($approve && ! $category->compensable()) {
            throw new FundBoundaryException(
                "THE BOUNDARY: the fund compensates protocol bugs only. A claim classified as {$category->value} "
                .'cannot be approved — not by sympathy, not by pressure, not by anyone. The exclusions were '
                .'stated up front, and expanding coverage is a constitutional-grade matter.'
            );
        }

        $claim->category = $category;
        $claim->status = $approve ? 'approved' : 'denied';
        $claim->decision_receipt = $decisionReceipt;
        $claim->decided_at = now();
        $claim->save();

        return $claim;
    }

    /**
     * Appeal a denied claim. The appeal path exists BY CHARTER; it
     * reopens assessment, it does not overturn the boundary.
     */
    public function appeal(LossFundClaim $claim, string $note): LossFundClaim
    {
        if ($claim->status !== 'denied') {
            throw new FundBoundaryException('Only a denied claim can be appealed.');
        }

        if (trim($note) === '') {
            throw new FundBoundaryException('An appeal must say why the decision was wrong.');
        }

        $claim->status = 'appealed';
        $claim->appealed_at = now();
        $claim->appeal_note = $note;
        $claim->save();

        return $claim;
    }

    /**
     * Attach a treasury-posted payout to an approved claim — after
     * VERIFYING, from the posted entries themselves, that the
     * transaction credits the approved claimant with the approved
     * amount. This module never posts; it only checks and records.
     */
    public function recordPayout(LossFundClaim $claim, string $transactionId): LossFundClaim
    {
        if ($claim->status !== 'approved') {
            throw new FundBoundaryException(
                'A payout can attach only to an approved claim — and only a protocol_bug claim can be approved.'
            );
        }

        $credited = (int) DB::table('entries')
            ->where('transaction_id', $transactionId)
            ->where('account_id', $claim->claimant_account_id)
            ->where('amount', '>', 0)
            ->sum('amount');

        if ($credited !== $claim->amount_minor) {
            throw new FundBoundaryException(
                "Payout verification failed: transaction {$transactionId} credits the claimant {$credited}, "
                ."but the approved claim is for {$claim->amount_minor}. The fund pays exactly what it decided."
            );
        }

        $claim->payout_transaction_id = $transactionId;
        $claim->save();

        return $claim;
    }
}
