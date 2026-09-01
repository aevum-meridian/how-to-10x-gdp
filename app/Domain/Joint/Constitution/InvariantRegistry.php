<?php

/**
 * SPDX-License-Identifier: LicenseRef-MVL-2.0 AND LicenseRef-AVL-2.0
 *
 * DOCUMENT 0.1 §CI — THE MACHINE-READABLE INVARIANT REGISTRY.
 *
 * "A continuous-integration check parses this document's logical forms and
 * the corresponding machine-readable invariant registry (a typed structure
 * in the codebase) and fails the build if any invariant's prose statement
 * and logical form, or the logical form and its enforcing test, are not in
 * declared correspondence."
 *
 * This class IS that typed structure. The prose and logical forms below are
 * verbatim copies of DOCUMENT 0.1; ProseLogicAgreementTest re-parses the
 * document at test time and fails if a single character drifted. The
 * declared enforcement points are verified to EXIST — the trigger in the
 * live catalog, the method in the codebase, the test file on disk — so an
 * invariant can never quietly lose one of its three legs.
 *
 * This registry has read-only authority: it describes enforcement, it
 * performs none, and it imports nothing from any posting path.
 *
 * © Maher
 */

declare(strict_types=1);

namespace App\Domain\Joint\Constitution;

use App\Domain\Joint\Constitution\Data\InvariantDefinition;

final class InvariantRegistry
{
    /** @var list<InvariantDefinition>|null */
    private static ?array $all = null;

    /** @return list<InvariantDefinition> */
    public static function all(): array
    {
        return self::$all ??= self::build();
    }

    public static function find(string $id): InvariantDefinition
    {
        foreach (self::all() as $definition) {
            if ($definition->id === $id) {
                return $definition;
            }
        }

        throw new \InvalidArgumentException("CONSTITUTION: no invariant \"{$id}\" is registered.");
    }

    /** @return list<InvariantDefinition> */
    private static function build(): array
    {
        return [
            new InvariantDefinition(
                id: 'I1',
                name: 'Conservation of Value',
                plainStatement: 'Every posted transaction neither creates nor destroys value. The signed amounts of the entries composing a transaction sum to exactly zero, per currency, with the sole exceptions of governed mint and burn transactions, which are themselves balanced against the system issuance and burn accounts.',
                logicalForm: '`∀ t ∈ Transactions where t.status = posted, ∀ c ∈ Currencies: Σ { e.amount | e ∈ entries(t) ∧ e.currency = c } = 0`',
                testName: 'ConservationTest',
                databaseEnforcement: ['trigger:trg_entries_conservation'],
                serviceEnforcement: ['App\Domain\Meridian\Ledger\Services\LedgerService::post'],
                harmPrevented: 'counterfeiting / silent dilution',
                licenseClause: 'MVL §M-C.13',
                amendmentTier: 'Constitutional',
            ),
            new InvariantDefinition(
                id: 'I2',
                name: 'Balance Integrity',
                plainStatement: "Every account's recorded balance equals the sum of all entries posted against it. No balance may diverge from the ledger that produces it.",
                logicalForm: '`∀ a ∈ Accounts: a.balance = Σ { e.amount | e ∈ Entries ∧ e.account = a }`',
                testName: 'BalanceReconcileTest',
                databaseEnforcement: ['trigger:trg_entries_balance_after', 'table:ledger_discrepancies'],
                serviceEnforcement: ['App\Domain\Meridian\Ledger\Services\LedgerService::reconcile'],
                harmPrevented: 'spendable ≠ recorded',
                licenseClause: 'MVL §M-C.13',
                amendmentTier: 'Constitutional',
            ),
            new InvariantDefinition(
                id: 'I3',
                name: 'Supply Integrity',
                plainStatement: 'For every currency, the sum of all account balances equals net issuance (total minted minus total burned). The whole equals the sum of its parts.',
                logicalForm: '`∀ c ∈ Currencies: Σ { a.balance | a ∈ Accounts ∧ a.currency = c } = mintedTotal(c) − burnedTotal(c)`',
                testName: 'SupplyProofTest',
                databaseEnforcement: ['trigger:trg_entries_conservation', 'table:ledger_discrepancies'],
                serviceEnforcement: ['App\Domain\Meridian\Ledger\Services\LedgerService::proveSupplyIntegrity'],
                harmPrevented: 'phantom / vanished supply',
                licenseClause: 'MVL §M-C.13',
                amendmentTier: 'Constitutional',
            ),
            new InvariantDefinition(
                id: 'I4',
                name: 'Honest Minting (Quorum)',
                plainStatement: 'A contribution credit comes into existence only when an M-of-N quorum of independent attesters has signed a valid attestation whose nonce is unused and whose expiry has not passed. No single attester can mint; no attestation can be replayed.',
                logicalForm: '`∀ m ∈ ContributionMints: m.valid ⟺ ( |{ s ∈ m.signatures | verify(s) }| ≥ M ) ∧ m.attesters ⊆ IndependentSet ∧ ¬used(m.nonce) ∧ now < m.expiry`',
                testName: 'AttestationQuorumTest',
                databaseEnforcement: ['unique:attestations_nonce_unique', 'trigger:trg_attestations_guard_mint', 'constraint:attestations_minted_requires_quorum'],
                serviceEnforcement: ['App\Domain\Meridian\Issuance\Services\IssuanceService::mintContribution'],
                harmPrevented: 'fabricated credits',
                licenseClause: 'MVL §M-C.13/C.12',
                amendmentTier: 'Constitutional',
            ),
            new InvariantDefinition(
                id: 'I5',
                name: 'Append-Only',
                plainStatement: 'No entry, once posted, is ever updated or deleted. Every correction is a new, additive, reversing entry that points to what it reverses. History is permanent.',
                logicalForm: "`∀ e ∈ Entries: once persisted(e), ∄ operation that mutates(e) ∨ removes(e); correction(e) ⟹ ∃ e' : e'.reverses = e ∧ e'.amount = −e.amount`",
                testName: 'ImmutabilityTest',
                databaseEnforcement: ['trigger:trg_entries_append_only', 'trigger:trg_transactions_append_only'],
                serviceEnforcement: ['App\Domain\Meridian\Ledger\Services\LedgerService::reverse'],
                harmPrevented: 'falsified history',
                licenseClause: 'MVL §M-C.13',
                amendmentTier: 'Constitutional',
            ),
            new InvariantDefinition(
                id: 'I6',
                name: 'No Punitive Debit (The Spine)',
                plainStatement: "No system actor may reduce a person's contribution-credit balance as a punishment, a behavioral incentive, or a means of control. The single permitted non-holder reduction is the reversal of a specific, identified fraudulent mint, and only that.",
                logicalForm: 'Let a debit `d` against a personal contribution account be *non-holder-initiated*. Then: `valid(d) ⟺ ∃ r : r.type = arbitration_reversal ∧ r.target_mint = (a specific txn_id) ∧ r.case = (a closed arbitration case_id) ∧ |d.amount| ≤ amount(r.target_mint) ∧ (balanceAfter(d) ≥ undisputedCredits(account))`. A debit referencing **no** specific target mint and **no** closed case is a *punitive debit* and is **always rejected**.',
                testName: 'NonPunishmentTest',
                databaseEnforcement: ['trigger:trg_entries_personal_debit_guard'],
                serviceEnforcement: [
                    'App\Domain\Meridian\Dispute\Services\DisputeService::applyArbitrationReversal',
                    'App\Domain\Meridian\Ledger\Services\LedgerService::post',
                ],
                harmPrevented: 'credits taken to control a person',
                licenseClause: 'MVL §M-C.10',
                amendmentTier: 'Constitutional (hardest)',
            ),
            new InvariantDefinition(
                id: 'I7',
                name: 'Issuance-Only Macro Control',
                plainStatement: 'The Policy Engine — the macro layer that adjusts the economy — may modify future issuance policies. It may never author an entry against any personal account. Its power is asymmetric by design: it circulates; it does not grasp.',
                logicalForm: '`∀ action ∈ PolicyEngineActions: action.writes ⊆ IssuancePolicies ∧ action.writes ∩ Entries = ∅`',
                testName: 'PolicyEngineNoEntryTest',
                databaseEnforcement: [
                    'no_privilege:meridian_policy_engine:entries:INSERT',
                    'no_privilege:meridian_policy_engine:transactions:INSERT',
                ],
                serviceEnforcement: ['App\Domain\Meridian\Policy\Services\PolicyEngineService::adjustIssuancePolicy'],
                harmPrevented: 'macro layer reaches a wallet',
                licenseClause: 'MVL §M-C.10',
                amendmentTier: 'Constitutional',
            ),
            new InvariantDefinition(
                id: 'I8',
                name: 'No Sensitive-Data Minting',
                plainStatement: 'No attestation path stores raw biometric, health, or neural data, and no currency is ever minted from neural/EEG data.',
                logicalForm: '`∀ p ∈ AttestationPayloads: p ∩ {raw_biometric, raw_health, neural} = ∅ ∧ ∀ c ∈ Currencies: source(c) ≠ neural`',
                testName: 'NoSensitivePIIMigrationTest',
                databaseEnforcement: ['event_trigger:trg_i8_sensitive_columns'],
                serviceEnforcement: ['App\Domain\Meridian\Issuance\Services\IssuanceService::instantiateCurrency'],
                harmPrevented: 'intimate surveillance',
                licenseClause: 'MVL §M-C.12',
                amendmentTier: 'Constitutional',
            ),
            new InvariantDefinition(
                id: 'I9',
                name: 'Personhood Is External',
                plainStatement: "Meridian and Aevum consume personhood as signed attestations from independent third-party providers, validated independently. Neither system is ever itself the personhood authority, and no provider's cross-context behavioral signal may feed a value, minting, or rights decision.",
                logicalForm: '`∀ pa ∈ PersonhoodAttestations: validate(pa) = independentSignatureCheck(pa) ∧ authority(personhood) ∉ {Meridian, Aevum} ∧ ∀ s ∈ ProviderRiskSignals: s ∉ inputs(ValueDecisions ∪ MintDecisions ∪ RightsDecisions)`',
                testName: 'PersonhoodBoundaryTest',
                databaseEnforcement: ['table:identities'],
                serviceEnforcement: ['App\Domain\Identity\Gas\GasIdentityProvider::verifyAttestation'],
                harmPrevented: 'single coercion point / imported social credit',
                licenseClause: 'MVL §M-C.11, AVL §A-C.11',
                amendmentTier: 'Constitutional',
            ),
            new InvariantDefinition(
                id: 'I10',
                name: 'Consensual Settlement',
                plainStatement: 'No settlement — whether between the two legs or with the outside world — moves or reverses value without holder-authorized intent on the debited side, except the I6 arbitration-bound reversal. An atomic abort restores the prior state exactly and never produces an unauthorized net debit.',
                logicalForm: '`∀ s ∈ Settlements: (∃ debit d ∈ s against personal account a) ⟹ authorized(d, holder(a)) ∨ isArbitrationReversal(d); ∀ s that abort: state_after(s) = state_before(s)`',
                testName: 'SettlementAbortTest',
                databaseEnforcement: ['trigger:trg_entries_personal_debit_guard'],
                serviceEnforcement: ['App\Domain\Meridian\Settlement\Services\SettlementCoordinator::settle'],
                harmPrevented: 'unauthorized debit hidden in sync',
                licenseClause: 'MVL §M-C.14',
                amendmentTier: 'Constitutional',
            ),
            new InvariantDefinition(
                id: 'I11',
                name: 'No Core Riba Issuance (the structural home of §C.9)',
                plainStatement: 'The issuance engine refuses to instantiate or mint any currency whose declared issuance policy constitutes Core Riba — a pre-fixed, guaranteed increase on money or same-kind fungible, irrespective of any real venture\'s outcome, with no genuine risk-bearing or value-creating counter-performance. This gives the Core Riba refusal a structural home so the license reinforces rather than leads (the consistency point: structure first, license as fallback, everywhere — including here).',
                logicalForm: '`∀ c ∈ Currencies at instantiation: coreRiba(c.issuance_policy) ⟹ reject(c)`, where `coreRiba(p) ⟺ p.base ∈ {money, same_kind_fungible} ∧ p.increase = prefixed_guaranteed ∧ ¬p.risk_bearing ∧ ¬p.value_creating ∧ p.extracts_from_counterparty`. All four conjuncts required; absence of any one removes the policy from the prohibition (then C.8 general permission governs).',
                testName: 'CoreRibaRejectionTest',
                databaseEnforcement: ['constraint:issuance_policies_no_core_riba'],
                serviceEnforcement: ['App\Domain\Meridian\Issuance\Services\IssuanceService::instantiateCurrency'],
                harmPrevented: 'usurious instrument issued',
                licenseClause: 'MVL §M-C.9, AVL §A-C.9',
                amendmentTier: 'Constitutional',
            ),
        ];
    }
}
