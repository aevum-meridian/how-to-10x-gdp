<?php

/**
 * SPDX-License-Identifier: LicenseRef-MVL-2.0 AND LicenseRef-AVL-2.0
 *
 * OpenApiDocumentTest (DEV-10, DOCUMENT 10.1).
 *
 * "The full OpenAPI document is published … and it is the single source
 * of truth for the API surface. Honest caveat: the API must never
 * expose an endpoint for a Research-tier capability as though it were
 * available (AVL-2.0 §A-§C.13) — the maturity endpoint is the binding
 * check, and the OpenAPI spec marks each operation's maturity."
 *
 * These tests hold the published document to that sentence:
 *   1. it is served, valid-shaped OpenAPI 3.1, under the correct media type;
 *   2. every declared path is a real route and every real /api/v1 route
 *      is declared — the document and the router cannot drift apart;
 *   3. every operation carries an x-maturity block whose label matches
 *      the live MaturityLedger row character for character;
 *   4. no operation is presented as available (nothing here is Shipped);
 *   5. the absent write surface is declared honestly, with reasons,
 *      instead of being silently omitted;
 *   6. money is specified as decimal strings, never floats.
 *
 * © Maher
 */

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Joint\Maturity\MaturityLedger;
use App\Http\Controllers\Api\V1\OpenApiController;
use Illuminate\Support\Facades\Route;

it('publishes the OpenAPI document as valid-shaped OpenAPI 3.1 JSON', function (): void {
    $response = $this->getJson('/api/v1/openapi.json');

    $response->assertOk();

    expect($response->headers->get('Content-Type'))
        ->toContain('application/vnd.oai.openapi+json');

    $doc = $response->json();

    expect($doc['openapi'])->toBe('3.1.0')
        ->and($doc['info']['title'])->toContain('Meridian')
        ->and($doc['info']['title'])->toContain('Aevum')
        ->and($doc['info']['license']['identifier'])
        ->toBe('LicenseRef-MVL-2.0 AND LicenseRef-AVL-2.0')
        ->and($doc['servers'][0]['url'])->toBe('/api/v1')
        ->and($doc['paths'])->toBeArray()->not->toBeEmpty();
});

it('is the single source of truth: document paths and live routes agree exactly', function (): void {
    $doc = OpenApiController::document();

    /** @var array<string, mixed> $paths */
    $paths = $doc['paths'];

    // Every path in the document resolves to a live GET route.
    $liveUris = [];
    foreach (Route::getRoutes() as $route) {
        if (str_starts_with($route->uri(), 'api/v1')) {
            $liveUris[] = '/'.$route->uri();
        }
    }

    foreach (array_keys($paths) as $declaredPath) {
        $expectedUri = '/api/v1'.$declaredPath;
        expect(in_array($expectedUri, $liveUris, true))
            ->toBeTrue("OpenAPI declares {$declaredPath} but no live route serves {$expectedUri}.");
    }

    // Every live /api/v1 route is declared in the document — the router
    // cannot quietly grow a surface the published spec does not admit to.
    foreach ($liveUris as $uri) {
        $declaredPath = substr($uri, strlen('/api/v1'));
        expect(array_key_exists($declaredPath, $paths))
            ->toBeTrue("Live route {$uri} is not declared in the OpenAPI document — the spec must be the single source of truth.");
    }
});

it('marks every operation with an x-maturity block that matches the live ledger verbatim', function (): void {
    $doc = OpenApiController::document();

    foreach ($doc['paths'] as $path => $operations) {
        foreach ($operations as $method => $operation) {
            expect(array_key_exists('x-maturity', $operation))
                ->toBeTrue("Operation {$method} {$path} carries no x-maturity block (DOCUMENT 10.1).");

            $block = $operation['x-maturity'];
            $ledger = MaturityLedger::find($block['subsystem']);

            expect($block['label'])->toBe($ledger->label->value)
                ->and($block['presentable_as_available'])->toBe($ledger->label->presentableAsAvailable())
                ->and($block['exit_criterion'])->toBe($ledger->exitCriterion)
                ->and($block['abandonment_criterion'])->toBe($ledger->abandonmentCriterion);
        }
    }
});

it('never presents an operation as available: nothing in this deployment is Shipped', function (): void {
    $doc = OpenApiController::document();

    foreach ($doc['paths'] as $path => $operations) {
        foreach ($operations as $method => $operation) {
            expect($operation['x-maturity']['label'])->not->toBe('shipped')
                ->and($operation['x-maturity']['presentable_as_available'])
                ->toBeFalse("Operation {$method} {$path} claims availability; no capability here has passed the DOCUMENT 9.4 audits.");
        }
    }

    expect($doc['info']['x-honest-note'])->toContain('Nothing in this deployment is Shipped');
});

it('declares the absent write surface honestly instead of silently omitting it', function (): void {
    $doc = OpenApiController::document();

    expect(array_key_exists('x-absent-operations', $doc))->toBeTrue();

    $absent = $doc['x-absent-operations'];
    expect($absent)->toBeArray()->not->toBeEmpty();

    $declaredPaths = array_column($absent, 'path');

    // The write surface DOCUMENT 10.1 describes must appear here, not in paths.
    foreach (['/transfers', '/contribution-mints', '/reserve/mint', '/reserve/redeem', '/attestations', '/bridge/deposit', '/bridge/withdraw'] as $writePath) {
        expect(in_array($writePath, $declaredPaths, true))
            ->toBeTrue("Absent write operation {$writePath} must be declared with its reason (AVL-2.0 §A-§C.13).")
            ->and(array_key_exists($writePath, $doc['paths']))
            ->toBeFalse("Write operation {$writePath} must NOT be in paths: the authenticated surface is InDevelopment.");
    }

    foreach ($absent as $entry) {
        expect($entry['reason'])->not->toBe('')
            ->and($entry['reason'])->toContain('AVL-2.0 §A-§C.13');
    }
});

it('specifies money as decimal strings over bigint minor units, never floats', function (): void {
    $doc = OpenApiController::document();

    $money = $doc['components']['schemas']['MoneyDecimalString'];

    expect($money['type'])->toBe('string')
        ->and($money['pattern'])->toBe('^-?\d+\.\d{2}$')
        ->and($money['description'])->toContain('bigint minor units');

    // The declared pattern must actually accept/reject the right shapes.
    $regex = '/'.str_replace('/', '\/', $money['pattern']).'/';
    expect(preg_match($regex, '425.00'))->toBe(1)
        ->and(preg_match($regex, '-75.00'))->toBe(1)
        ->and(preg_match($regex, '425.0'))->toBe(0)
        ->and(preg_match($regex, '4.2e2'))->toBe(0);
});
