<?php

/**
 * SPDX-License-Identifier: LicenseRef-MVL-2.0 AND LicenseRef-AVL-2.0
 *
 * The typed, machine-readable invariant registry mandated by
 * DOCUMENT 0.1 — THE INVARIANT SPECIFICATION §CI (the prose-logic agreement
 * gate). No invariant may exist in prose without a logical form, an
 * enforcement triple, and a test; none may exist in code without an entry
 * in Document 0.1. The ProseLogicAgreementTest parses Document 0.1 and this
 * registry and fails CI if they drift.
 *
 * © Maher
 */

declare(strict_types=1);

namespace App\Domain\Shared\Invariants;

enum Invariant: string
{
    case I1 = 'I1';
    case I2 = 'I2';
    case I3 = 'I3';
    case I4 = 'I4';
    case I5 = 'I5';
    case I6 = 'I6';
    case I7 = 'I7';
    case I8 = 'I8';
    case I9 = 'I9';
    case I10 = 'I10';
    case I11 = 'I11';

    public function title(): string
    {
        return match ($this) {
            self::I1 => 'Conservation of Value',
            self::I2 => 'Balance Integrity',
            self::I3 => 'Supply Integrity',
            self::I4 => 'Honest Minting (Quorum)',
            self::I5 => 'Append-Only',
            self::I6 => 'No Punitive Debit (The Spine)',
            self::I7 => 'Issuance-Only Macro Control',
            self::I8 => 'No Sensitive-Data Minting',
            self::I9 => 'Personhood Is External',
            self::I10 => 'Consensual Settlement',
            self::I11 => 'No Core Riba Issuance',
        };
    }

    /**
     * Plain-language statement, in declared correspondence with the prose of
     * Document 0.1. The ProseLogicAgreementTest asserts each statement's
     * anchor phrase appears in Document 0.1.
     */
    public function proseStatement(): string
    {
        return match ($this) {
            self::I1 => 'Every posted transaction neither creates nor destroys value. The signed amounts of the entries composing a transaction sum to exactly zero, per currency.',
            self::I2 => "Every account's recorded balance equals the sum of all entries posted against it.",
            self::I3 => 'For every currency, the sum of all account balances equals net issuance (total minted minus total burned).',
            self::I4 => 'A contribution credit comes into existence only when an M-of-N quorum of independent attesters has signed a valid attestation whose nonce is unused and whose expiry has not passed.',
            self::I5 => 'No entry, once posted, is ever updated or deleted. Every correction is a new, additive, reversing entry that points to what it reverses.',
            self::I6 => "No system actor may reduce a person's contribution-credit balance as a punishment, a behavioral incentive, or a means of control. The single permitted non-holder reduction is the reversal of a specific, identified fraudulent mint.",
            self::I7 => 'The Policy Engine may modify future issuance policies. It may never author an entry against any personal account.',
            self::I8 => 'No attestation path stores raw biometric, health, or neural data, and no currency is ever minted from neural/EEG data.',
            self::I9 => "Meridian and Aevum consume personhood as signed attestations from independent third-party providers. Neither system is ever itself the personhood authority, and no provider's cross-context behavioral signal may feed a value, minting, or rights decision.",
            self::I10 => 'No settlement moves or reverses value without holder-authorized intent on the debited side, except the I6 arbitration-bound reversal. An atomic abort restores the prior state exactly.',
            self::I11 => 'The issuance engine refuses to instantiate or mint any currency whose declared issuance policy constitutes Core Riba.',
        };
    }

    /**
     * A fragment of the logical form that must appear verbatim in
     * Document 0.1 — the machine-checkable side of the §CI gate.
     */
    public function logicalFormAnchor(): string
    {
        return match ($this) {
            self::I1 => 'Σ { e.amount | e ∈ entries(t) ∧ e.currency = c } = 0',
            self::I2 => 'a.balance = Σ { e.amount | e ∈ Entries ∧ e.account = a }',
            self::I3 => 'mintedTotal(c) − burnedTotal(c)',
            self::I4 => '¬used(m.nonce) ∧ now < m.expiry',
            self::I5 => "e'.reverses = e ∧ e'.amount = −e.amount",
            self::I6 => 'balanceAfter(d) ≥ undisputedCredits(account)',
            self::I7 => 'action.writes ⊆ IssuancePolicies ∧ action.writes ∩ Entries = ∅',
            self::I8 => 'p ∩ {raw_biometric, raw_health, neural} = ∅',
            self::I9 => 's ∉ inputs(ValueDecisions ∪ MintDecisions ∪ RightsDecisions)',
            self::I10 => 'state_after(s) = state_before(s)',
            self::I11 => 'coreRiba(c.issuance_policy) ⟹ reject(c)',
        };
    }

    /**
     * The canonical enforcing test, per Document 0.1 §XREF and DEV-0.
     * The ProseLogicAgreementTest asserts this test file exists and
     * references this invariant.
     */
    public function enforcingTest(): string
    {
        return match ($this) {
            self::I1 => 'ConservationTest',
            self::I2 => 'BalanceReconcileTest',
            self::I3 => 'SupplyProofTest',
            self::I4 => 'AttestationQuorumTest',
            self::I5 => 'ImmutabilityTest',
            self::I6 => 'NonPunishmentTest',
            self::I7 => 'PolicyEngineNoEntryTest',
            self::I8 => 'NoSensitivePIIMigrationTest',
            self::I9 => 'PersonhoodBoundaryTest',
            self::I10 => 'SettlementAbortTest',
            self::I11 => 'CoreRibaRejectionTest',
        };
    }

    /**
     * All eleven invariants are constitutional; I6 is the hardest to amend.
     */
    public function isConstitutional(): bool
    {
        return true;
    }

    public function isHardestToAmend(): bool
    {
        return $this === self::I6;
    }
}
