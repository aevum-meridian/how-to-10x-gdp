<?php

/**
 * SPDX-License-Identifier: LicenseRef-MVL-2.0 AND LicenseRef-AVL-2.0
 *
 * DOCUMENT 10.1 — "The full OpenAPI document is published so any
 * OIDC/standards-compliant client can integrate, and it is the single
 * source of truth for the API surface."
 *
 * The document is BUILT from the codebase, not hand-copied: every
 * operation's `x-maturity` block is read live from the MaturityLedger
 * (DOCUMENT 3.4), so the spec can never claim a maturity the ledger
 * does not grant. Operations the prose describes but this deployment
 * does not expose (transfers, mints, settlement — the authenticated
 * write surface) are declared in `x-absent-operations` with the honest
 * reason for their absence, instead of being silently omitted or,
 * worse, listed as available (AVL-2.0 §A-§C.13).
 *
 * © Maher
 */

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Joint\Maturity\MaturityLedger;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

final class OpenApiController extends Controller
{
    public function __invoke(): JsonResponse
    {
        return new JsonResponse(self::document(), 200, [
            'Content-Type' => 'application/vnd.oai.openapi+json; version=3.1',
        ]);
    }

    /**
     * The OpenAPI 3.1 document. Public and static so the test suite can
     * assert against exactly what a client would download.
     *
     * @return array<string, mixed>
     */
    public static function document(): array
    {
        $api = MaturityLedger::find('public-api-v1');

        return [
            'openapi' => '3.1.0',
            'info' => [
                'title' => 'Meridian × Aevum Public API',
                'version' => '1.0.0-indevelopment',
                'summary' => 'The versioned, honest, read-only public surface (DOCUMENT 10.1).',
                'description' => 'Single source of truth for the API surface. Every operation '
                    .'carries an x-maturity block read live from the Maturity & Abandonment '
                    .'Ledger (DOCUMENT 3.4); no operation may be presented as available unless '
                    .'its label permits it (AVL-2.0 §A-§C.13). Every money value is a decimal '
                    .'string over bigint minor units. The maturity endpoint is the binding '
                    .'check every other surface must consult.',
                'license' => [
                    'name' => 'LicenseRef-MVL-2.0 AND LicenseRef-AVL-2.0',
                    'identifier' => 'LicenseRef-MVL-2.0 AND LicenseRef-AVL-2.0',
                ],
                'x-maturity' => self::maturity('public-api-v1'),
                'x-honest-note' => 'Nothing in this deployment is Shipped. The API surface itself is '
                    .$api->label->value.'; the invariant suite is green but Shipped additionally '
                    .'requires the external audits (DOCUMENT 9.4) and production operation.',
            ],
            'servers' => [
                ['url' => '/api/v1', 'description' => 'Versioned public surface.'],
            ],
            'paths' => [
                '/maturity' => [
                    'get' => self::operation(
                        operationId: 'getMaturityLedger',
                        summary: 'The Maturity & Abandonment Ledger — the binding check every other surface must consult.',
                        subsystem: 'public-api-v1',
                        description: 'Every capability with its label (Shipped / InDevelopment / Research / '
                            .'DeprecatedRemoved), its exit criterion AND its abandonment criterion. '
                            .'DeprecatedRemoved rows are never deleted (DOCUMENT 3.4).',
                    ),
                ],
                '/trade-off-register' => [
                    'get' => self::operation(
                        operationId: 'getTradeOffRegister',
                        summary: 'The machine-readable Trade-off Register: every design tension with its honest cost.',
                        subsystem: 'public-api-v1',
                        description: 'Each entry: {id, axis, chosen, cost, spec_source}. The cost field is '
                            .'never empty — a trade-off without a disclosed cost is an overclaim.',
                    ),
                ],
                '/currencies' => [
                    'get' => self::operation(
                        operationId: 'getCurrencyRegistry',
                        summary: 'The currency registry: read public; write governance-gated, refusing Core Riba policies (I11).',
                        subsystem: 'core-riba-refusal',
                        description: 'Each currency with its issuance-policy Core Riba flags. There is '
                            .'deliberately no write route: policy changes are parametric governance acts.',
                    ),
                ],
                '/transparency-log' => [
                    'get' => self::operation(
                        operationId: 'getTransparencyLog',
                        summary: 'The public, verifiable cross-system event chain and its registered signers.',
                        subsystem: 'cross-system-event-contract',
                        description: 'Paged (?after_seq). Hash-chained, Ed25519-signed events; discrepancies '
                            .'are surfaced, never auto-corrected (DOCUMENT 7.1, 7.2).',
                        parameters: [[
                            'name' => 'after_seq',
                            'in' => 'query',
                            'required' => false,
                            'schema' => ['type' => 'integer', 'minimum' => 0],
                            'description' => 'Return events with seq greater than this value (page size 100).',
                        ]],
                    ),
                ],
                '/incidents' => [
                    'get' => self::operation(
                        operationId: 'getIncidentDisclosures',
                        summary: 'The incident disclosure clock: public promises, publicly checkable (DOCUMENT 8.1).',
                        subsystem: 'crisis-response-loss-fund',
                        description: 'Declared incidents with severity, status, disclosure deadlines and '
                            .'whether each disclosure is published, pending, or OVERDUE. The incident '
                            .'commander has NO ledger power.',
                    ),
                ],
                '/openapi.json' => [
                    'get' => self::operation(
                        operationId: 'getOpenApiDocument',
                        summary: 'This document — the single source of truth for the API surface (DOCUMENT 10.1).',
                        subsystem: 'public-api-v1',
                        description: 'Built from the codebase at request time; maturity labels are read live '
                            .'from the Maturity Ledger and can never drift from it.',
                    ),
                ],
            ],
            'components' => [
                'schemas' => [
                    'MoneyDecimalString' => [
                        'type' => 'string',
                        'pattern' => '^-?\\d+\\.\\d{2}$',
                        'description' => 'Every money value is a decimal string over bigint minor units '
                            .'(DEV-0). Floats never touch money.',
                        'examples' => ['425.00', '-75.00'],
                    ],
                    'MaturityLabel' => [
                        'type' => 'string',
                        'enum' => ['shipped', 'in_development', 'research', 'deprecated_removed'],
                        'description' => 'DOCUMENT 3.4. Only "shipped" may be presented as available.',
                    ],
                ],
                'securitySchemes' => [
                    'gasIdentity' => [
                        'type' => 'openIdConnect',
                        'openIdConnectUrl' => 'urn:aevum:gas:oidc-discovery:in-development',
                        'description' => 'GAS adapter in front of Sanctum (first-party) and Passport '
                            .'(third-party OAuth2). InDevelopment: not yet accepted by any operation '
                            .'in this document — declared so OIDC clients can see what is coming, '
                            .'not so they can use it.',
                    ],
                ],
            ],
            'x-absent-operations' => self::absentOperations(),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $parameters
     * @return array<string, mixed>
     */
    private static function operation(
        string $operationId,
        string $summary,
        string $subsystem,
        string $description,
        array $parameters = [],
    ): array {
        $operation = [
            'operationId' => $operationId,
            'summary' => $summary,
            'description' => $description,
            'x-maturity' => self::maturity($subsystem),
            'responses' => [
                '200' => [
                    'description' => 'OK (JSON).',
                    'content' => ['application/json' => ['schema' => ['type' => 'object']]],
                ],
                '429' => ['description' => 'Rate limited (throttle:60,1).'],
            ],
        ];

        if ($parameters !== []) {
            $operation['parameters'] = $parameters;
        }

        return $operation;
    }

    /**
     * The x-maturity block, read live from the ledger.
     *
     * @return array<string, mixed>
     */
    private static function maturity(string $subsystem): array
    {
        $entry = MaturityLedger::find($subsystem);

        return [
            'subsystem' => $entry->subsystem,
            'label' => $entry->label->value,
            'presentable_as_available' => $entry->label->presentableAsAvailable(),
            'exit_criterion' => $entry->exitCriterion,
            'abandonment_criterion' => $entry->abandonmentCriterion,
        ];
    }

    /**
     * The write surface DOCUMENT 10.1 describes but this deployment does
     * not expose — declared with the honest reason, per AVL-2.0 §A-§C.13.
     *
     * @return list<array<string, string>>
     */
    private static function absentOperations(): array
    {
        $reason = 'The domain service exists and is invariant-gated, but the authenticated '
            .'HTTP face requires the GAS adapter in front of Sanctum/Passport with '
            .'holder-authorization tokens; that auth stack is InDevelopment. Exposing '
            .'this operation unauthenticated would present an unavailable capability '
            .'as available (AVL-2.0 §A-§C.13).';

        return [
            ['method' => 'POST', 'path' => '/transfers', 'capability' => 'Idempotent transfers proposed through the event contract', 'reason' => $reason],
            ['method' => 'POST', 'path' => '/contribution-mints', 'capability' => 'Contribution mint requiring a valid PoVC quorum attestation', 'reason' => $reason],
            ['method' => 'POST', 'path' => '/reserve/mint', 'capability' => 'Reserve mint (licensed-tunnel only, with proof-of-backing)', 'reason' => $reason],
            ['method' => 'POST', 'path' => '/reserve/redeem', 'capability' => 'Reserve redeem (licensed-tunnel only)', 'reason' => $reason],
            ['method' => 'POST', 'path' => '/attestations', 'capability' => 'Attestation submission by registered verifiers', 'reason' => $reason],
            ['method' => 'POST', 'path' => '/bridge/deposit', 'capability' => 'Bridge deposit (membrane-filtered)', 'reason' => $reason],
            ['method' => 'POST', 'path' => '/bridge/withdraw', 'capability' => 'Bridge withdraw (membrane-filtered)', 'reason' => $reason],
            ['method' => 'GET', 'path' => '/accounts/{id}/balance', 'capability' => 'Balance with verifiable proof (requires the authenticated identity surface)', 'reason' => $reason],
            ['method' => 'GET', 'path' => '/accounts/{id}/history', 'capability' => "The user's exportable verifiable history", 'reason' => $reason],
        ];
    }
}
