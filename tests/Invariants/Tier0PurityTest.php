<?php

// SPDX-License-Identifier: LicenseRef-AVL-2.0

declare(strict_types=1);

namespace Tests\Invariants;

use App\Domain\Aevum\Fabric\Tier0\PegBasketRebalancer;
use App\Domain\Aevum\Fabric\Tier0\Tier0Input;
use App\Domain\Aevum\Fabric\Tier0\Tier0Rule;
use Symfony\Component\Finder\Finder;
use Tests\TestCase;

/**
 * Tier0PurityTest — A-§C.12 (No ML on the Trusted Path).
 *
 * DOCUMENT 4.4: Tier-0 rules are verified pure — deterministic, side-
 * effect free, capped, ML-free. Any ML lives only in the Tier-1
 * advisory proposer, which emits inert Proposal records and executes
 * nothing. A single side-effecting or non-deterministic stabilizer
 * breaks the guarantee, so purity is tested property-style, not
 * trusted.
 */
final class Tier0PurityTest extends TestCase
{
    /** Every registered Tier-0 rule, enumerated for the property sweep. */
    private const TIER0_RULES = [
        PegBasketRebalancer::class,
    ];

    /**
     * Architectural wall: nothing in the Tier-0 namespace may import
     * an ML/HTTP/random/clock/persistence dependency. The forbidden
     * vocabulary covers learned components AND every impurity channel
     * a hidden side effect would need.
     */
    public function test_no_ml_or_impurity_dependency_is_importable_into_the_tier0_path(): void
    {
        $forbidden = [
            // ML / learned components
            'TensorFlow', 'OpenAI', 'Rubix', 'ml\\', 'Phpml', 'Onnx', 'torch',
            // I/O and persistence
            'Illuminate\\Support\\Facades\\DB', 'Illuminate\\Support\\Facades\\Http',
            'Illuminate\\Database', 'Eloquent', 'file_get_contents', 'file_put_contents',
            'fopen', 'curl_', 'Redis', 'Cache::', 'Storage::',
            // Non-determinism
            'random_int', 'random_bytes', 'mt_rand', 'rand(', 'uniqid',
            'now()', 'time()', 'date(', 'microtime', 'Carbon',
        ];

        $files = iterator_to_array(
            (new Finder())->files()->in(app_path('Domain/Aevum/Fabric/Tier0'))->name('*.php')
        );
        $this->assertNotEmpty($files);

        foreach ($files as $file) {
            $source = (string) file_get_contents($file->getPathname());
            foreach ($forbidden as $token) {
                $this->assertFalse(
                    str_contains($source, $token),
                    "A-§C.12 violated: Tier-0 file {$file->getRelativePathname()} "
                    ."references the impure/ML channel \"{$token}\"."
                );
            }
        }
    }

    /**
     * Property: determinism. Same input, same output — across many
     * randomized inputs, repeated evaluation is bit-identical.
     */
    public function test_every_tier0_rule_is_deterministic(): void
    {
        foreach (self::TIER0_RULES as $ruleClass) {
            $rule = new $ruleClass();
            $this->assertInstanceOf(Tier0Rule::class, $rule);

            for ($i = 0; $i < 50; $i++) {
                $input = $this->randomInput($i);

                $first = $rule->evaluate($input);
                $second = $rule->evaluate($input);
                $third = $rule->evaluate($input);

                $this->assertSame(
                    $first->relativeAdjustments,
                    $second->relativeAdjustments,
                    "A-§C.12 violated: {$rule->ruleId()} is non-deterministic."
                );
                $this->assertSame($second->relativeAdjustments, $third->relativeAdjustments);
                $this->assertSame($first->epoch, $input->epoch);
                $this->assertSame($first->ruleId, $rule->ruleId());
            }
        }
    }

    /**
     * Property: the output is independent of input array ORDER — a
     * subtle impurity channel (iteration-order dependence) that would
     * make the rule's behavior depend on how data arrived.
     */
    public function test_tier0_output_is_independent_of_input_ordering(): void
    {
        foreach (self::TIER0_RULES as $ruleClass) {
            $rule = new $ruleClass();

            $targets = ['gold' => 0.4, 'wheat' => 0.35, 'energy' => 0.25];
            $currents = ['gold' => 0.5, 'wheat' => 0.3, 'energy' => 0.2];

            $forward = $rule->evaluate(new Tier0Input(
                epoch: 7,
                observedPrices: [],
                targetWeights: $targets,
                currentWeights: $currents,
            ));

            $reversed = $rule->evaluate(new Tier0Input(
                epoch: 7,
                observedPrices: [],
                targetWeights: array_reverse($targets, preserve_keys: true),
                currentWeights: array_reverse($currents, preserve_keys: true),
            ));

            $this->assertSame(
                $forward->relativeAdjustments,
                $reversed->relativeAdjustments,
                "A-§C.12 violated: {$rule->ruleId()} depends on input ordering."
            );
        }
    }

    /**
     * Property: the per-epoch movement cap holds across randomized
     * inputs INCLUDING adversarial extremes — no input can make the
     * rule emit an adjustment beyond its own declared cap.
     */
    public function test_every_tier0_rule_respects_its_movement_cap(): void
    {
        foreach (self::TIER0_RULES as $ruleClass) {
            $rule = new $ruleClass();
            $cap = $rule->movementCap();

            $this->assertGreaterThan(0.0, $cap);
            $this->assertLessThanOrEqual(1.0, $cap);

            for ($i = 0; $i < 50; $i++) {
                $input = $this->randomInput($i);
                $adjustment = $rule->evaluate($input);

                $this->assertLessThanOrEqual(
                    $cap + 1e-12,
                    $adjustment->maxAbsoluteAdjustment(),
                    "A-§C.12 violated: {$rule->ruleId()} exceeded its per-epoch cap {$cap}."
                );
            }

            // Adversarial extreme: a 100% weight gap still clamps to κ.
            $extreme = $rule->evaluate(new Tier0Input(
                epoch: 1,
                observedPrices: [],
                targetWeights: ['a' => 1.0],
                currentWeights: ['a' => 0.0],
            ));
            $this->assertSame($cap, $extreme->maxAbsoluteAdjustment());
        }
    }

    /**
     * Property: side-effect freedom, observed. Evaluation writes no
     * database row anywhere — the rule holds no mutable state of its
     * own (DOCUMENT 4.4: "pure functions over inputs").
     */
    public function test_tier0_evaluation_writes_nothing(): void
    {
        $countRows = static function (): int {
            $total = 0;
            foreach (['entries', 'transactions', 'experience_specs', 'asset_labels',
                'global_blocks', 'user_client_preferences', 'issuance_policies',
                'proxy_metrics', 'policy_actions', 'circuit_breakers'] as $table) {
                $total += (int) \Illuminate\Support\Facades\DB::table($table)->count();
            }

            return $total;
        };

        $before = $countRows();

        foreach (self::TIER0_RULES as $ruleClass) {
            $rule = new $ruleClass();
            for ($i = 0; $i < 10; $i++) {
                $rule->evaluate($this->randomInput($i));
            }
        }

        $this->assertSame(
            $before,
            $countRows(),
            'A-§C.12 violated: a Tier-0 evaluation produced a persistent write.'
        );
    }

    /**
     * The Tier-1 advisory proposer's output type is inert: data-only,
     * no methods that execute, schedule, or touch anything.
     */
    public function test_the_tier1_advisory_proposal_is_inert(): void
    {
        $reflection = new \ReflectionClass(\App\Domain\Aevum\Fabric\Tier1\AdvisoryProposal::class);

        $ownMethods = array_filter(
            $reflection->getMethods(\ReflectionMethod::IS_PUBLIC),
            static fn (\ReflectionMethod $m): bool => $m->getDeclaringClass()->getName() === $reflection->getName(),
        );

        $names = array_map(static fn (\ReflectionMethod $m): string => $m->getName(), $ownMethods);
        $this->assertSame(
            ['__construct'],
            array_values($names),
            'A-§C.12 violated: AdvisoryProposal exposes behavior beyond construction — '
            .'it must be inert data.'
        );

        foreach ($reflection->getProperties() as $property) {
            if ($property->getDeclaringClass()->getName() !== $reflection->getName()) {
                continue; // Framework base-class plumbing, not proposal state.
            }
            $this->assertTrue(
                $property->isReadOnly(),
                "AdvisoryProposal::\${$property->getName()} must be readonly."
            );
        }
    }

    /** Deterministic pseudo-random input generation (seeded — the TEST must be reproducible too). */
    private function randomInput(int $seed): Tier0Input
    {
        mt_srand($seed * 7919 + 13);

        $assets = ['gold', 'wheat', 'energy', 'water', 'labor'];
        $targets = [];
        $currents = [];
        $prices = [];

        foreach ($assets as $asset) {
            $targets[$asset] = mt_rand(0, 1000) / 1000;
            $currents[$asset] = mt_rand(0, 1000) / 1000;
            $prices[$asset] = mt_rand(1, 100000) / 100;
        }

        return new Tier0Input(
            epoch: $seed,
            observedPrices: $prices,
            targetWeights: $targets,
            currentWeights: $currents,
        );
    }
}
