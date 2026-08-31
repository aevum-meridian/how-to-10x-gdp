<?php

/**
 * SPDX-License-Identifier: LicenseRef-MVL-2.0 AND LicenseRef-AVL-2.0
 *
 * DOCUMENT 0.1 §CI — one row of the machine-readable invariant registry.
 *
 * "No invariant may exist in prose without a logical form, an enforcement
 * triple, and a test; none may exist in code without an entry here."
 *
 * Every field is verbatim-bound to DOCUMENT 0.1: the ProseLogicAgreementTest
 * (DEV-9) parses the constitutional document and fails the build if this
 * structure and the prose drift apart in either direction.
 *
 * © Maher
 */

declare(strict_types=1);

namespace App\Domain\Joint\Constitution\Data;

final readonly class InvariantDefinition
{
    /**
     * @param non-empty-string $id "I1" … "I11"
     * @param non-empty-string $name the invariant's title, verbatim
     * @param non-empty-string $plainStatement the constitutional prose, verbatim
     * @param non-empty-string $logicalForm the formal statement, verbatim
     * @param non-empty-string $testName the enforcing test per §XREF (a file in tests/Invariants)
     * @param list<non-empty-string> $databaseEnforcement live DB objects, each in the grammar
     *        "trigger:{name}" | "constraint:{name}" | "unique:{index}" |
     *        "event_trigger:{name}" | "table:{name}" |
     *        "no_privilege:{role}:{table}:{privilege}" (the role must LACK it)
     * @param list<non-empty-string> $serviceEnforcement "FQCN::method" guard points
     * @param non-empty-string $harmPrevented per §XREF
     * @param non-empty-string $licenseClause per §XREF
     * @param non-empty-string $amendmentTier per §XREF
     */
    public function __construct(
        public string $id,
        public string $name,
        public string $plainStatement,
        public string $logicalForm,
        public string $testName,
        public array $databaseEnforcement,
        public array $serviceEnforcement,
        public string $harmPrevented,
        public string $licenseClause,
        public string $amendmentTier,
    ) {
        if (preg_match('/^I(1[01]|[1-9])$/', $id) !== 1) {
            throw new \InvalidArgumentException(
                "CONSTITUTION: invariant id must be I1…I11, got \"{$id}\"."
            );
        }

        if ($databaseEnforcement === [] || $serviceEnforcement === []) {
            throw new \InvalidArgumentException(
                "CONSTITUTION: invariant {$id} must declare its full enforcement "
                .'triple — at least one database object AND one service guard '
                .'(the test is the third leg). An invariant enforced fewer than '
                .'three times is not an invariant of this system.'
            );
        }
    }
}
