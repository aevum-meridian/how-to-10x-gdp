<?php

/**
 * SPDX-License-Identifier: LicenseRef-MVL-2.0
 *
 * DEV-4.2 — the declared issuance policy for a currency, carrying the
 * five typed Core-Riba flags that make I11 machine-checkable
 * (DOCUMENT 4.2 "Data model additions", DOCUMENT 2.1 §6). © Maher
 */

declare(strict_types=1);

namespace App\Domain\Meridian\Issuance\Data;

use App\Domain\Meridian\Issuance\Enums\BaseKind;
use App\Domain\Meridian\Issuance\Enums\IncreaseKind;
use App\Domain\Meridian\Issuance\Enums\IssuanceType;
use App\Domain\Meridian\Ledger\Enums\CurrencyFamily;
use Spatie\LaravelData\Data;

final class CurrencyPolicy extends Data
{
    /**
     * @param non-empty-string $code
     * @param non-empty-string $name
     * @param int<0, max> $decimals
     * @param array<string, mixed> $params
     * @param list<float>|null $declaredLossDistribution Sampled returns
     *     (as fractions of principal, negative = loss) demonstrating the
     *     provider's real downside when risk_bearing is claimed
     *     (DOCUMENT 2.1 §6.2: Var(return) > 0 with real downside).
     */
    public function __construct(
        public readonly string $code,
        public readonly string $name,
        public readonly CurrencyFamily $family,
        public readonly int $decimals,
        public readonly IssuanceType $type,
        public readonly BaseKind $baseKind,
        public readonly IncreaseKind $increaseKind,
        public readonly bool $riskBearing,
        public readonly bool $valueCreating,
        public readonly bool $extractsFromCounterparty,
        public readonly ?int $maxSupply = null,
        public readonly array $params = [],
        public readonly ?array $declaredLossDistribution = null,
        public readonly bool $neuralSourced = false,
    ) {
    }

    /**
     * The four-element Core-Riba conjunction (DOCUMENT 2.1 §6.1). All
     * four must hold simultaneously for the forbidden form.
     */
    public function isSquarelyCoreRiba(): bool
    {
        return $this->baseKind->isRibaEligibleBase()
            && $this->increaseKind === IncreaseKind::PrefixedGuaranteed
            && ! $this->riskBearing
            && ! $this->valueCreating
            && $this->extractsFromCounterparty;
    }

    /**
     * DOCUMENT 2.1 §6.3: the mathematical test for genuine risk-bearing
     * is a non-degenerate loss distribution — Var(return) > 0 WITH real
     * downside. A claimed risk_bearing flag with a degenerate (constant
     * or loss-free) distribution is not genuine.
     */
    public function hasGenuineRiskBearing(): bool
    {
        if (! $this->riskBearing) {
            return false;
        }

        $samples = $this->declaredLossDistribution;

        if ($samples === null || count($samples) < 2) {
            return false; // Claim without evidence fails closed.
        }

        $mean = array_sum($samples) / count($samples);
        $variance = 0.0;

        foreach ($samples as $s) {
            $variance += ($s - $mean) ** 2;
        }
        $variance /= count($samples);

        $hasDownside = count(array_filter($samples, fn (float $s): bool => $s < 0.0)) > 0;

        return $variance > 0.0 && $hasDownside;
    }
}
