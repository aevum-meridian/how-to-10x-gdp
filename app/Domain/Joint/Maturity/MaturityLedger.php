<?php

/**
 * SPDX-License-Identifier: LicenseRef-MVL-2.0 AND LicenseRef-AVL-2.0
 *
 * DOCUMENT 3.4 — THE MATURITY & ABANDONMENT LEDGER.
 *
 * "In a system built on Sidq, the maturity ledger is load-bearing: it is
 * the single source of truth that prevents every other surface —
 * marketing, documentation, governance, user-facing copy — from
 * overclaiming. The licenses bind to it (AVL-2.0 §A-§C.13: no surface
 * may present a Research capability as Shipped)."
 *
 * Surfaced at GET /api/v1/maturity — "the binding check every surface
 * consults" (DEV-10). The rows below are the DOCUMENT 3.4 §2 table,
 * verbatim in substance, with THIS deployment's honest labels: nothing
 * in this repository is Shipped. The build enforces the invariants and
 * the suite is green, but "Shipped" additionally requires the external
 * audits (DOCUMENT 9.4) and production operation — claiming it here
 * would be the exact overclaim this ledger exists to forbid.
 *
 * A label change is a parametric governance act (DOCUMENT 3.4 §4),
 * which in code means: a reviewed, committed change to this file — and
 * the DeprecatedRemoved entries are never deleted.
 *
 * © Maher
 */

declare(strict_types=1);

namespace App\Domain\Joint\Maturity;

use App\Domain\Joint\Maturity\Data\MaturityEntry;
use App\Domain\Joint\Maturity\Enums\MaturityLabel;

final class MaturityLedger
{
    /** @var list<MaturityEntry>|null */
    private static ?array $entries = null;

    /** @return list<MaturityEntry> */
    public static function all(): array
    {
        return self::$entries ??= self::build();
    }

    public static function find(string $subsystem): MaturityEntry
    {
        foreach (self::all() as $entry) {
            if ($entry->subsystem === $subsystem) {
                return $entry;
            }
        }

        throw new \InvalidArgumentException(
            "MATURITY: subsystem \"{$subsystem}\" has no ledger row. A capability "
            .'without a maturity label may not be surfaced at all (AVL-2.0 §A-§C.13).'
        );
    }

    /**
     * The binding check (DEV-10): a surface may present a capability as
     * available ONLY if this returns true.
     */
    public static function presentableAsAvailable(string $subsystem): bool
    {
        return self::find($subsystem)->label->presentableAsAvailable();
    }

    /** @return list<MaturityEntry> */
    private static function build(): array
    {
        return [
            new MaturityEntry(
                subsystem: 'ledger-core',
                label: MaturityLabel::InDevelopment,
                exitCriterion: 'DB+service+tests enforce I1–I3, I5 under concurrency; independent audit confirms append-only and conservation',
                abandonmentCriterion: 'a path is found that mutates/deletes an entry or breaks conservation that cannot be closed structurally',
            ),
            new MaturityEntry(
                subsystem: 'non-punishment-spine',
                label: MaturityLabel::InDevelopment,
                exitCriterion: 'independent audit confirms no protocol path punitively debits a personal balance; 5.2 proof passes',
                abandonmentCriterion: 'any path debits a personal balance without a closed arbitration case + specific mint',
            ),
            new MaturityEntry(
                subsystem: 'povc-quorum-minting',
                label: MaturityLabel::Research,
                exitCriterion: 'M-of-N quorum with replay protection live and tested',
                abandonmentCriterion: 'no attester configuration resists collusion without slashing that excludes honest verifiers',
            ),
            new MaturityEntry(
                subsystem: 'personhood-layer',
                label: MaturityLabel::Research,
                exitCriterion: '≥2 independent providers with published FA/FR; fairness audit with published demographic false-reject rates; appeal path within bound',
                abandonmentCriterion: 'no provider config achieves a false-reject rate low enough to avoid systematic exclusion of any major demographic/region',
            ),
            new MaturityEntry(
                subsystem: 'core-riba-refusal',
                label: MaturityLabel::InDevelopment,
                exitCriterion: 'four-element predicate implemented; engine rejects clear Core Riba; Shariah board reviews edge cases',
                abandonmentCriterion: 'the predicate cannot distinguish usury from permitted yield without excluding legitimate finance',
            ),
            new MaturityEntry(
                subsystem: 'dispute-clawback-arbitration',
                label: MaturityLabel::Research,
                exitCriterion: 'one provisional-mint clawback resolved end-to-end through human arbitration without touching an innocent balance',
                abandonmentCriterion: 'dispute resolution cannot protect innocent holders without making fraud risk-free',
            ),
            new MaturityEntry(
                subsystem: 'policy-engine',
                label: MaturityLabel::Research,
                exitCriterion: 'proxy-divergence monitoring throttles a test issuance; I7 (no personal-entry) proven',
                abandonmentCriterion: 'the engine cannot adjust the macro economy without any path to a personal balance',
            ),
            new MaturityEntry(
                subsystem: 'settlement-hands',
                label: MaturityLabel::Research,
                exitCriterion: 'atomic abort formally model-checked (5.3) to never net-debit an unauthorized balance',
                abandonmentCriterion: 'the abort path cannot be proven to leave no unauthorized debit',
            ),
            new MaturityEntry(
                subsystem: 'membrane',
                label: MaturityLabel::InDevelopment,
                exitCriterion: 'inbound/outbound controls live; GAS anti-correlation wall passes PersonhoodBoundaryTest',
                abandonmentCriterion: 'the anti-correlation wall cannot be made compile-time enforceable',
            ),
            new MaturityEntry(
                subsystem: 'cross-system-event-contract',
                label: MaturityLabel::Research,
                exitCriterion: 'signed/hash-chained/idempotent contract round-trips; reconciliation proves zero drift over a defined period',
                abandonmentCriterion: 'reconciliation cannot detect drift within an acceptable window',
            ),
            new MaturityEntry(
                subsystem: 'crisis-response-loss-fund',
                label: MaturityLabel::Research,
                exitCriterion: 'timelines and Loss Fund exercised in a drill; coverage boundary (bugs not market risk) defended',
                abandonmentCriterion: 'the Loss Fund boundary cannot be defended against moral hazard / adverse selection',
            ),
            new MaturityEntry(
                subsystem: 'data-erasure-crypto-shredding',
                label: MaturityLabel::InDevelopment,
                exitCriterion: 'erasure reconciles with I5 via tombstones; legal-hold exception bounded',
                abandonmentCriterion: 'crypto-shredding cannot satisfy erasure law without breaking append-only',
            ),
            new MaturityEntry(
                subsystem: 'contribution-credits',
                label: MaturityLabel::Research,
                exitCriterion: 'per-credit spec bundle (virtue/proxy/divergence/dispute/lifecycle) complete; independent virtue signal exists',
                abandonmentCriterion: 'no independent virtue signal exists for a given credit (then that credit is not minted)',
            ),
            new MaturityEntry(
                subsystem: 'currency-flux',
                label: MaturityLabel::Research,
                exitCriterion: 'community-circulation pilot meets its scoped metrics',
                abandonmentCriterion: 'marketed or used as general-purpose store of value (scope violation)',
            ),
            new MaturityEntry(
                subsystem: 'currency-peg-regulated',
                label: MaturityLabel::InDevelopment,
                exitCriterion: 'regulated tunnel licensed; basket rebalancer verified Tier-0',
                abandonmentCriterion: 'basket cannot hold purchasing power within governed band',
            ),
            new MaturityEntry(
                subsystem: 'currency-peg-plus-experimental',
                label: MaturityLabel::Research,
                exitCriterion: 'jurisdiction-gating + risk-acknowledgment live',
                abandonmentCriterion: 'cannot be gated out of markets where unlawful',
            ),
            new MaturityEntry(
                subsystem: 'currency-focus-eeg',
                label: MaturityLabel::DeprecatedRemoved,
                exitCriterion: '— (retired on the no-neural-data principle)',
                abandonmentCriterion: '— (kept as honest historical record)',
            ),
            new MaturityEntry(
                subsystem: 'eight-billion-users',
                label: MaturityLabel::Research,
                exitCriterion: 'personhood coverage + sharded throughput + reconciliation + crisis drill all pass at the relevant scale milestone',
                abandonmentCriterion: 'personhood coverage remains insufficient to responsibly serve the stated population — in which case the user-scale claim is formally revised downward rather than the safety floors relaxed',
            ),
            new MaturityEntry(
                subsystem: 'public-api-v1',
                label: MaturityLabel::InDevelopment,
                exitCriterion: 'full OpenAPI surface live behind GAS + Sanctum/Passport with per-endpoint rate limits; every operation carries its maturity label',
                abandonmentCriterion: 'the API cannot avoid exposing a Research capability as available (AVL-2.0 §A-§C.13)',
            ),
        ];
    }
}
