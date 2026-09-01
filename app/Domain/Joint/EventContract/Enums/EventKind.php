<?php

/**
 * SPDX-License-Identifier: LicenseRef-MVL-2.0 AND LicenseRef-AVL-2.0
 *
 * DEV-7.1 — the typed message vocabulary (DOCUMENT 7.1 §2). Proposals
 * flow Aevum → Meridian; confirmations flow back; reconciliation
 * alerts are raised by the nightly job. The contract carries proposals
 * and confirmations — never commands. © Maher
 */

declare(strict_types=1);

namespace App\Domain\Joint\EventContract\Enums;

enum EventKind: string
{
    case ProposalTransfer = 'proposal.transfer';
    case ProposalFilterVerdict = 'proposal.filter_verdict';
    case ProposalPolicyChange = 'proposal.policy_change';
    case ProposalCurrencyRegistration = 'proposal.currency_registration';
    case ConfirmationCommitted = 'confirmation.committed';
    case ConfirmationRejected = 'confirmation.rejected';
    case ReconciliationAlert = 'reconciliation.alert';

    public function isProposal(): bool
    {
        return str_starts_with($this->value, 'proposal.');
    }
}
