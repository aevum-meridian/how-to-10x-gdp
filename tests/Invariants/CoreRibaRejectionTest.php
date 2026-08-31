<?php

// SPDX-License-Identifier: LicenseRef-MVL-2.0

declare(strict_types=1);

namespace Tests\Invariants;

use App\Domain\Meridian\Issuance\Data\CurrencyPolicy;
use App\Domain\Meridian\Issuance\Enums\BaseKind;
use App\Domain\Meridian\Issuance\Enums\IncreaseKind;
use App\Domain\Meridian\Issuance\Enums\IssuanceType;
use App\Domain\Meridian\Issuance\Exceptions\CoreRibaPolicyException;
use App\Domain\Meridian\Issuance\Exceptions\SensitiveDataException;
use App\Domain\Meridian\Issuance\Models\IssuancePolicy;
use App\Domain\Meridian\Issuance\Services\IssuanceService;
use App\Domain\Meridian\Ledger\Enums\CurrencyFamily;
use App\Domain\Meridian\Ledger\Models\Currency;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * CoreRibaRejectionTest — Invariant I11 (No Core Riba Issuance).
 *
 * DOCUMENT 0.1 I11 / DOCUMENT 2.1 §6 / DOCUMENT 4.2: a
 * fixed-guaranteed-interest-on-idle-money policy is ALWAYS rejected;
 * profit-and-loss-sharing, rent, service-fee, staking-for-real-work and
 * demurrage policies are ALWAYS accepted; a policy claiming risk-bearing
 * must expose a non-degenerate loss distribution.
 */
final class CoreRibaRejectionTest extends TestCase
{
    private IssuanceService $issuance;

    protected function setUp(): void
    {
        parent::setUp();
        $this->issuance = app(IssuanceService::class);
    }

    private function policy(
        BaseKind $base,
        IncreaseKind $increase,
        bool $risk,
        bool $value,
        bool $extracts,
        ?array $lossDistribution = null,
    ): CurrencyPolicy {
        return new CurrencyPolicy(
            code: 'RIB'.strtoupper(Str::random(8)),
            name: 'Riba Test Currency',
            family: CurrencyFamily::Reserve,
            decimals: 2,
            type: IssuanceType::Reserve1To1,
            baseKind: $base,
            increaseKind: $increase,
            riskBearing: $risk,
            valueCreating: $value,
            extractsFromCounterparty: $extracts,
            declaredLossDistribution: $lossDistribution,
        );
    }

    public function test_fixed_guaranteed_interest_on_idle_money_is_always_rejected(): void
    {
        // The forbidden form (DOCUMENT 2.1 §6.1): P·(1+r), r pre-fixed and
        // guaranteed, no risk borne, no service, extraction from the
        // counterparty. All four conjuncts hold — refused, always.
        foreach ([BaseKind::Money, BaseKind::SameKindFungible] as $base) {
            try {
                $this->issuance->instantiateCurrency($this->policy(
                    $base,
                    IncreaseKind::PrefixedGuaranteed,
                    risk: false,
                    value: false,
                    extracts: true,
                ));
                $this->fail('I11 violated: a squarely-Core-Riba policy was instantiated.');
            } catch (CoreRibaPolicyException $e) {
                $this->assertStringContainsString('I11', $e->getMessage());
            }
        }

        $this->assertSame(0, IssuancePolicy::query()->count(), 'No Core-Riba policy row may persist.');
    }

    public function test_the_database_check_rejects_a_core_riba_row_even_if_the_service_is_bypassed(): void
    {
        $currency = new Currency([
            'code' => 'BYP'.strtoupper(Str::random(8)),
            'name' => 'Bypass Test',
            'family' => CurrencyFamily::Reserve,
            'decimals' => 2,
        ]);
        $currency->save();

        try {
            DB::table('issuance_policies')->insert([
                'id' => (string) Str::ulid(),
                'currency_id' => $currency->id,
                'type' => 'reserve_1to1',
                'params' => '{}',
                'base_kind' => 'money',
                'increase_kind' => 'prefixed_guaranteed',
                'risk_bearing' => false,
                'value_creating' => false,
                'extracts_from_counterparty' => true,
            ]);
            $this->fail('I11 violated: the DB CHECK permitted a squarely-Core-Riba policy row.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('issuance_policies_no_core_riba', $e->getMessage());
        }
    }

    public function test_the_permitted_yield_generators_are_always_accepted(): void
    {
        // DOCUMENT 2.1 §6.2 — each lacks at least one Core-Riba element.
        $permitted = [
            // Profit-and-loss sharing: return not pre-fixed, risk borne.
            $this->policy(
                BaseKind::Money,
                IncreaseKind::ProfitAndLossShare,
                risk: true,
                value: true,
                extracts: false,
                lossDistribution: [-0.2, 0.05, 0.15, -0.1, 0.3]
            ),
            // Asset rent: base is a real asset, real value transferred.
            $this->policy(
                BaseKind::RealAsset,
                IncreaseKind::Rent,
                risk: true,
                value: true,
                extracts: false,
                lossDistribution: [-0.05, 0.08, 0.1]
            ),
            // Genuine service fee: real work rendered.
            $this->policy(
                BaseKind::Service,
                IncreaseKind::ServiceFee,
                risk: false,
                value: true,
                extracts: false
            ),
            // Staking for real validation work.
            $this->policy(
                BaseKind::Contribution,
                IncreaseKind::StakingReward,
                risk: true,
                value: true,
                extracts: false,
                lossDistribution: [-1.0, 0.02, 0.04]
            ),
            // Demurrage (a negative yield / holding cost, not an increase).
            $this->policy(
                BaseKind::Money,
                IncreaseKind::Demurrage,
                risk: false,
                value: false,
                extracts: false
            ),
        ];

        foreach ($permitted as $policy) {
            $currency = $this->issuance->instantiateCurrency($policy);
            $this->assertNotNull($currency->id, "Permitted policy {$policy->increaseKind->value} must instantiate.");
        }

        $this->assertSame(count($permitted), IssuancePolicy::query()->count());
    }

    public function test_a_risk_bearing_claim_without_a_non_degenerate_loss_distribution_does_not_rescue_core_riba(): void
    {
        // DOCUMENT 2.1 §6.3: Var(return) > 0 with real downside is the
        // mathematical signature of genuine risk-bearing.
        $degenerates = [
            null,                       // no evidence at all
            [0.05, 0.05, 0.05],         // constant: Var = 0
            [0.02, 0.04, 0.08],         // upside-only: no real downside
        ];

        foreach ($degenerates as $distribution) {
            try {
                $this->issuance->instantiateCurrency($this->policy(
                    BaseKind::Money,
                    IncreaseKind::PrefixedGuaranteed,
                    risk: true,
                    value: false,
                    extracts: true,
                    lossDistribution: $distribution,
                ));
                $this->fail('I11 violated: a degenerate risk claim rescued a Core-Riba policy.');
            } catch (CoreRibaPolicyException $e) {
                $this->assertStringContainsString('non-degenerate', $e->getMessage());
            }
        }

        // But a GENUINE loss distribution on the same shape is a real
        // risk-bearing construction — the interpretive supremacy of
        // permission applies at the boundary and it is accepted.
        $currency = $this->issuance->instantiateCurrency($this->policy(
            BaseKind::Money,
            IncreaseKind::PrefixedGuaranteed,
            risk: true,
            value: false,
            extracts: true,
            lossDistribution: [-0.5, 0.1, 0.2, -0.05],
        ));
        $this->assertNotNull($currency->id);
    }

    public function test_neural_sourced_currencies_are_refused_at_instantiation(): void
    {
        $policy = new CurrencyPolicy(
            code: 'NRL'.strtoupper(Str::random(8)),
            name: 'Neural Metric Currency',
            family: CurrencyFamily::Contribution,
            decimals: 2,
            type: IssuanceType::Povc,
            baseKind: BaseKind::Contribution,
            increaseKind: IncreaseKind::None,
            riskBearing: false,
            valueCreating: true,
            extractsFromCounterparty: false,
            neuralSourced: true,
        );

        $this->expectException(SensitiveDataException::class);
        $this->issuance->instantiateCurrency($policy);
    }
}
