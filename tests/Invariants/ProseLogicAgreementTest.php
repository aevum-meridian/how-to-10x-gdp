<?php

/**
 * SPDX-License-Identifier: LicenseRef-MVL-2.0 AND LicenseRef-AVL-2.0
 *
 * ProseLogicAgreementTest — DOCUMENT 0.1 §CI (DEV-9).
 *
 * "A continuous-integration check parses this document's logical forms and
 * the corresponding machine-readable invariant registry … and fails the
 * build if any invariant's prose statement and logical form, or the logical
 * form and its enforcing test, are not in declared correspondence. No
 * invariant may exist in prose without a logical form, an enforcement
 * triple, and a test; none may exist in code without an entry here."
 *
 * Four directions of drift, each caught:
 *   1. prose → registry   (the registry's text is verbatim from the document)
 *   2. registry → prose   (no invariant exists in code without prose)
 *   3. registry → tests   (the declared test file exists, names its
 *                          invariant, and is verbatim per §XREF)
 *   4. registry → world   (every declared DB object exists in the live
 *                          catalog; every declared service guard exists in
 *                          the codebase; the roles declared powerless ARE)
 *
 * © Maher
 */

declare(strict_types=1);

use App\Domain\Joint\Constitution\Data\InvariantDefinition;
use App\Domain\Joint\Constitution\InvariantRegistry;
use Illuminate\Support\Facades\DB;

/**
 * Parse DOCUMENT 0.1 into [id => [name, plain, logical]] — the same shape
 * the registry declares, extracted independently from the constitution.
 *
 * @return array<string, array{name: string, plain: string, logical: string}>
 */
function parseConstitution(): array
{
    $path = base_path('docs/DOCUMENT 0.1 — THE INVARIANT SPECIFICATION.txt');
    expect(is_file($path))->toBeTrue('The constitutional document itself is missing.');

    $document = str_replace("\r\n", "\n", (string) file_get_contents($path));

    preg_match_all(
        '/^## (I\d+) — (.+?)$(.*?)(?=^## |^---|\z)/msu',
        $document,
        $matches,
        PREG_SET_ORDER
    );

    $parsed = [];

    foreach ($matches as $section) {
        if (preg_match('/^I\d+$/', $section[1]) !== 1) {
            continue;
        }

        preg_match('/\*\*Plain statement\.\*\* (.+?)\n\n/su', $section[3], $plain);
        preg_match('/\*\*Logical form\.\*\* (.+?)\n\n/su', $section[3], $logical);

        $parsed[$section[1]] = [
            'name' => trim($section[2]),
            'plain' => trim($plain[1] ?? ''),
            'logical' => trim($logical[1] ?? ''),
        ];
    }

    return $parsed;
}

/**
 * Parse the §XREF cross-reference matrix into [id => row].
 *
 * @return array<string, array{test: string, harm: string, clause: string, tier: string}>
 */
function parseCrossReferenceMatrix(): array
{
    $path = base_path('docs/DOCUMENT 0.1 — THE INVARIANT SPECIFICATION.txt');
    $document = str_replace("\r\n", "\n", (string) file_get_contents($path));

    preg_match_all(
        '/^\| (I\d+) [^|]*\| `(\w+)` \| ([^|]+) \| ([^|]+) \| ([^|]+) \|$/mu',
        $document,
        $matches,
        PREG_SET_ORDER
    );

    $rows = [];

    foreach ($matches as $row) {
        $rows[$row[1]] = [
            'test' => trim($row[2]),
            'harm' => trim($row[3]),
            'clause' => trim($row[4]),
            'tier' => trim(str_replace('**', '', $row[5])),
        ];
    }

    return $rows;
}

describe('ProseLogicAgreementTest (DOCUMENT 0.1 §CI)', function (): void {
    test('the constitution declares exactly eleven invariants and the registry carries exactly those', function (): void {
        $prose = parseConstitution();
        $registry = InvariantRegistry::all();

        expect(array_keys($prose))->toBe(['I1', 'I2', 'I3', 'I4', 'I5', 'I6', 'I7', 'I8', 'I9', 'I10', 'I11'])
            ->and(count($registry))->toBe(11);

        // Direction 2: none may exist in code without an entry in prose.
        foreach ($registry as $definition) {
            expect(array_key_exists($definition->id, $prose))->toBeTrue(
                "Registry declares {$definition->id} but the constitution has no such invariant."
            );
        }

        // Direction 1: none may exist in prose without an entry in code.
        foreach (array_keys($prose) as $id) {
            expect(InvariantRegistry::find($id))->toBeInstanceOf(InvariantDefinition::class);
        }
    });

    test('every plain statement, logical form, and name in the registry is VERBATIM from the constitution', function (): void {
        $prose = parseConstitution();

        foreach (InvariantRegistry::all() as $definition) {
            $constitutional = $prose[$definition->id];

            expect($constitutional['plain'])->not->toBe('')
                ->and($constitutional['logical'])->not->toBe('');

            // Character-for-character. A silently reworded invariant is a
            // silently amended constitution — the gate exists to refuse it.
            expect($definition->name)->toBe(
                $constitutional['name'],
                "{$definition->id}: registry name drifted from the constitution."
            );
            expect($definition->plainStatement)->toBe(
                $constitutional['plain'],
                "{$definition->id}: registry plain statement drifted from the constitution."
            );
            expect($definition->logicalForm)->toBe(
                $constitutional['logical'],
                "{$definition->id}: registry logical form drifted from the constitution."
            );
        }
    });

    test('every invariant declares its §XREF test, harm, clause, and tier verbatim — and the test file exists and names its invariant', function (): void {
        $matrix = parseCrossReferenceMatrix();
        expect(count($matrix))->toBe(11);

        foreach (InvariantRegistry::all() as $definition) {
            $row = $matrix[$definition->id];

            expect($definition->testName)->toBe($row['test'])
                ->and($definition->harmPrevented)->toBe($row['harm'])
                ->and($definition->licenseClause)->toBe($row['clause'])
                ->and($definition->amendmentTier)->toBe($row['tier']);

            // The declared correspondence between logical form and enforcing
            // test: the file exists AND self-identifies with its invariant id.
            $testFile = base_path("tests/Invariants/{$definition->testName}.php");
            expect(is_file($testFile))->toBeTrue(
                "{$definition->id}: declared test {$definition->testName} does not exist."
            );

            $source = (string) file_get_contents($testFile);
            expect((bool) preg_match('/\b'.preg_quote($definition->id, '/').'\b/', $source))->toBeTrue(
                "{$definition->id}: {$definition->testName} never mentions the invariant it enforces."
            );
        }
    });

    test('every declared database enforcement object EXISTS in the live catalog and every declared powerless role IS powerless', function (): void {
        foreach (InvariantRegistry::all() as $definition) {
            foreach ($definition->databaseEnforcement as $declaration) {
                $parts = explode(':', $declaration);
                $kind = $parts[0];

                match ($kind) {
                    'trigger' => expect(
                        DB::selectOne('SELECT 1 AS ok FROM pg_trigger WHERE tgname = ? AND NOT tgisinternal', [$parts[1]])
                    )->not->toBeNull("{$definition->id}: trigger {$parts[1]} is not in the live catalog."),
                    'constraint' => expect(
                        DB::selectOne('SELECT 1 AS ok FROM pg_constraint WHERE conname = ?', [$parts[1]])
                    )->not->toBeNull("{$definition->id}: constraint {$parts[1]} is not in the live catalog."),
                    'unique' => expect(
                        DB::selectOne("SELECT 1 AS ok FROM pg_indexes WHERE indexname = ? AND indexdef ILIKE '%unique%'", [$parts[1]])
                    )->not->toBeNull("{$definition->id}: unique index {$parts[1]} is not in the live catalog."),
                    'event_trigger' => expect(
                        DB::selectOne('SELECT 1 AS ok FROM pg_event_trigger WHERE evtname = ?', [$parts[1]])
                    )->not->toBeNull("{$definition->id}: event trigger {$parts[1]} is not in the live catalog."),
                    'table' => expect(
                        DB::selectOne('SELECT 1 AS ok FROM pg_tables WHERE schemaname = ? AND tablename = ?', ['public', $parts[1]])
                    )->not->toBeNull("{$definition->id}: table {$parts[1]} is not in the live catalog."),
                    'no_privilege' => expect(
                        (bool) DB::selectOne('SELECT has_table_privilege(?, ?, ?) AS held', [$parts[1], $parts[2], $parts[3]])->held
                    )->toBeFalse("{$definition->id}: role {$parts[1]} HOLDS {$parts[3]} on {$parts[2]} — the declared powerlessness is a lie."),
                    default => throw new LogicException("Unknown enforcement grammar: {$declaration}"),
                };
            }
        }
    });

    test('every declared service guard exists as a real method in the codebase', function (): void {
        foreach (InvariantRegistry::all() as $definition) {
            foreach ($definition->serviceEnforcement as $guard) {
                [$class, $method] = explode('::', $guard);

                expect(class_exists($class))->toBeTrue(
                    "{$definition->id}: declared guard class {$class} does not exist."
                );
                expect(method_exists($class, $method))->toBeTrue(
                    "{$definition->id}: declared guard {$guard} does not exist."
                );
            }
        }
    });

    test('the registry is descriptive, not authoritative: it imports no posting path and holds no ledger power', function (): void {
        foreach ([
            app_path('Domain/Joint/Constitution/InvariantRegistry.php'),
            app_path('Domain/Joint/Constitution/Data/InvariantDefinition.php'),
        ] as $file) {
            $source = (string) file_get_contents($file);

            // Enforcement points may be NAMED as strings (that is the
            // registry's whole purpose) but never IMPORTED or invoked.
            expect($source)->not->toContain('use App\Domain\Meridian')
                ->not->toContain('use App\Domain\Aevum')
                ->not->toContain('->post(')
                ->not->toContain('new TransactionDraft')
                ->not->toContain('new EntryDraft');
        }
    });
});
