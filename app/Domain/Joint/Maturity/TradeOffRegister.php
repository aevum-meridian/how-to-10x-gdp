<?php

/**
 * SPDX-License-Identifier: LicenseRef-MVL-2.0 AND LicenseRef-AVL-2.0
 *
 * THE TRADE-OFF REGISTER — surfaced at GET /api/v1/trade-off-register
 * (DOCUMENT 10.1: "the machine-readable Trade-off Register").
 *
 * Every entry names a real tension this system resolved by CHOOSING,
 * states what was chosen, and states — without softening — what the
 * choice costs. The register is the structural antidote to the oldest
 * failure of engineered systems: presenting a trade-off as a free
 * lunch. Nothing here is a bug report; everything here is a decision
 * the project stands behind and discloses.
 *
 * © Maher
 */

declare(strict_types=1);

namespace App\Domain\Joint\Maturity;

use App\Domain\Joint\Maturity\Data\TradeOffEntry;

final class TradeOffRegister
{
    /** @var list<TradeOffEntry>|null */
    private static ?array $entries = null;

    /** @return list<TradeOffEntry> */
    public static function all(): array
    {
        return self::$entries ??= self::build();
    }

    /** @return list<TradeOffEntry> */
    private static function build(): array
    {
        return [
            new TradeOffEntry(
                id: 'non-punishment-vs-fraud-recovery',
                axis: 'holder-protection-vs-fraud-recovery',
                chosen: 'Only the Dispute Engine may debit a personal contribution balance, and only to reverse a specific proven-fraudulent mint through a closed arbitration case (I6). Punitive debits are structurally impossible three ways over.',
                cost: 'Fraud recovery is slower and narrower than a discretionary clawback power would allow; some fraud losses that a punitive system could recoup are borne instead by process and by the Protocol-Loss Fund boundary.',
                specSource: 'DOCUMENT 0.1 I6 / DOCUMENT 4.3 / DOCUMENT 5.2',
            ),
            new TradeOffEntry(
                id: 'issuance-only-macro-control',
                axis: 'macro-steering-vs-wallet-reach',
                chosen: 'The Policy Engine adjusts FUTURE issuance only; it holds no database privilege and no compile-time path to author an entry against any account (I7). Detected gaming throttles the faucet, never the reservoir.',
                cost: 'The macro layer cannot correct past over-issuance by touching balances; correction is slower and works only on the margin of new supply.',
                specSource: 'DOCUMENT 0.1 I7 / DOCUMENT 4.5 / DOCUMENT 9.3',
            ),
            new TradeOffEntry(
                id: 'erasure-vs-append-only',
                axis: 'privacy-vs-immutability',
                chosen: 'PII lives off-ledger, encrypted per-record; erasure destroys ciphertext and key, leaving an immutable tombstone. The ledger stays append-only (I5) while the person becomes unreadable.',
                cost: 'Erasure is cryptographic, not physical: ciphertext previously exfiltrated stays out there, protected only as long as the cryptography holds. Legal holds can defer (never deny) erasure for a bounded, disclosed period.',
                specSource: 'DOCUMENT 6.5',
            ),
            new TradeOffEntry(
                id: 'offline-double-spend-bound',
                axis: 'offline-access-vs-double-spend',
                chosen: 'Offline value moves through pre-reserved vouchers; double-spend is BOUNDED to the reserved amount rather than pretended away. The custodial tier exists only behind an explicit acknowledged disclosure.',
                cost: 'Within the reserved bound, offline double-spend is possible by physical necessity; the honest design limits the blast radius instead of claiming elimination.',
                specSource: 'DOCUMENT 6.3',
            ),
            new TradeOffEntry(
                id: 'privacy-vs-compliance',
                axis: 'privacy-vs-compliance',
                chosen: 'AML/sanctions checks run at the membrane\'s regulated-tunnel face using selective-disclosure proofs (prove "not sanctioned" without revealing identity) wherever law permits.',
                cost: 'Where law does not permit minimized disclosure, the regulated tunnel discloses more; users in those jurisdictions get less privacy, and the register says so rather than hiding it.',
                specSource: 'DOCUMENT 3.3 / DOCUMENT 6.4',
            ),
            new TradeOffEntry(
                id: 'partial-personhood-coverage',
                axis: 'inclusion-vs-sybil-resistance',
                chosen: 'Personhood is consumed from independent external providers (I9) with a rung ladder that grants partial, capped participation at low rungs rather than excluding the undocumented outright.',
                cost: 'Coverage is honestly partial: the hardest-to-verify humans are the ones who most need inclusion, and no provider configuration yet proves planetary-scale fairness. The 8-billion claim stays Research until it does.',
                specSource: 'DOCUMENT 6.1 / DOCUMENT 6.2 / DOCUMENT 3.4',
            ),
            new TradeOffEntry(
                id: 'loss-fund-boundary',
                axis: 'user-restitution-vs-moral-hazard',
                chosen: 'The Protocol-Loss Fund covers protocol bugs ONLY; market risk, user error, and disclosed experimental losses are structurally unapprovable (a DB CHECK, not a policy).',
                cost: 'Users who lose value to market moves or their own mistakes are not made whole, ever — the fund refuses to become an insurance product that would invite moral hazard and adverse selection.',
                specSource: 'DOCUMENT 8.2',
            ),
            new TradeOffEntry(
                id: 'flux-losing-trade',
                axis: 'circulation-vs-store-of-value',
                chosen: '$FLUX carries demurrage by design: holding it idle is SUPPOSED to be a losing trade, with deductions flowing to a dividend pool as balanced transactions.',
                cost: 'Anyone treating $FLUX as savings loses value predictably; the scope statement exists so that no one can claim they were not told.',
                specSource: 'DOCUMENT 1.1 / DOCUMENT 2.1',
            ),
            new TradeOffEntry(
                id: 'una-velocity-not-cpi',
                axis: 'ubi-stability-vs-index-honesty',
                chosen: '$UNA v1 stabilises per-capita issuance against measured on-chain velocity, not a cost-of-living index — because a robust purchasing-power index is harder than a national CPI and must be earned with published methodology.',
                cost: '$UNA v1 does NOT guarantee purchasing-power stability; calling it a velocity stabiliser is the honest name, and the cost-of-living version remains future work.',
                specSource: 'DOCUMENT 2.1',
            ),
            new TradeOffEntry(
                id: 'reserve-attestation-freshness',
                axis: 'mint-availability-vs-proof-of-backing',
                chosen: 'Reserve-backed minting fails CLOSED: no attestation, a stale attestation, or an attested shortfall refuses the mint and (on shortfall) fires the circuit breaker and declares an S1 incident automatically.',
                cost: 'A custodian outage halts legitimate reserve mints until a fresh attestation arrives; availability is sacrificed to backing honesty.',
                specSource: 'DOCUMENT 8.3',
            ),
            new TradeOffEntry(
                id: 'ethical-source-not-osi',
                axis: 'openness-vs-ethical-floors',
                chosen: 'MVL-2.0/AVL-2.0 are ethical-source licenses carrying the constitutional floors into every fork; the project is NOT OSI/FSF open source and never claims to be.',
                cost: 'The licenses restrict fields of use, which forfeits OSI-label network effects and some contributor goodwill; the register records this as a chosen price, not an oversight.',
                specSource: 'MVL-2.0 §0.2 / AVL-2.0 / DOCUMENT 10.7',
            ),
            new TradeOffEntry(
                id: 'formal-proofs-tcb-bounded',
                axis: 'assurance-vs-claim-strength',
                chosen: 'The keystone properties (conservation, non-punishment) are formally verified AND property-tested under real concurrency, because the proofs abstract concurrency away and the tests sample a finite space.',
                cost: 'Neither proofs nor tests alone prove universal correctness; the guarantee is conditional on the Trusted Computing Base, and teaching the guarantee without the condition is forbidden.',
                specSource: 'DOCUMENT 5.1 / DOCUMENT 9.2 §4 / DOCUMENT 10.6',
            ),
        ];
    }
}
