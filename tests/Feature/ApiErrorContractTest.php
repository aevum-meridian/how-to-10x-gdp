<?php

/**
 * SPDX-License-Identifier: LicenseRef-MVL-2.0 AND LicenseRef-AVL-2.0
 *
 * ApiErrorContractTest (DEV-10, DOCUMENT 10.1 — developer experience).
 *
 * The error surface is part of the honest surface. A 404 on a posting
 * path must not be a bare shrug: it must point the caller at the OpenAPI
 * document, whose x-absent-operations section explains WHY the endpoint
 * does not exist. Errors are stable JSON, never leak exception classes
 * or stack traces, and 405 says plainly that the surface is read-only.
 *
 * © Maher
 */

declare(strict_types=1);

namespace Tests\Feature;

it('returns a stable JSON error shape for unknown API routes, pointing at the source of truth', function (): void {
    $response = $this->getJson('/api/v1/nonexistent');

    $response->assertNotFound();
    $response->assertJsonStructure(['error' => ['status', 'message', 'docs']]);
    $response->assertJsonPath('error.status', 404);
    $response->assertJsonPath('error.docs', '/api/v1/openapi.json');

    expect($response->json('error.message'))
        ->toContain('single source of truth')
        ->toContain('x-absent-operations');
});

it('answers a posting attempt on an absent endpoint with the honest 404, not a silent one', function (): void {
    // A developer who read DOCUMENT 10.1 prose might try POST /transfers.
    $response = $this->postJson('/api/v1/transfers', ['amount' => '10.00']);

    $response->assertNotFound();
    expect($response->json('error.message'))->toContain('x-absent-operations');
});

it('answers a write method on a read endpoint with a plain-language 405', function (): void {
    foreach (['post', 'put', 'patch', 'delete'] as $method) {
        $response = $this->{$method.'Json'}('/api/v1/maturity');

        $response->assertStatus(405);
        $response->assertJsonPath('error.status', 405);
        expect($response->json('error.message'))
            ->toContain('read-only')
            ->toContain(strtoupper($method));
    }
});

it('never leaks exception classes, file paths, or stack traces in API errors', function (): void {
    foreach ([
        $this->getJson('/api/v1/nonexistent'),
        $this->postJson('/api/v1/maturity'),
    ] as $response) {
        $body = (string) $response->getContent();

        expect($body)->not->toContain('Symfony\\')
            ->and($body)->not->toContain('Illuminate\\')
            ->and($body)->not->toContain('exception')
            ->and($body)->not->toContain('/home/')
            ->and($body)->not->toContain('vendor/');
    }
});

it('serves CORS for the public read surface so third parties can verify from a browser', function (): void {
    // The transparency log is only "public, verifiable" (DOCUMENT 10.1)
    // if an independent verifier's browser page may actually fetch it.
    $response = $this->get('/api/v1/transparency-log', ['Origin' => 'https://independent-verifier.example']);

    $response->assertOk();
    expect($response->headers->get('Access-Control-Allow-Origin'))->toBe('*');

    // Preflight succeeds and allows only GET — the surface stays read-only.
    $preflight = $this->call('OPTIONS', '/api/v1/maturity', [], [], [], [
        'HTTP_ORIGIN' => 'https://independent-verifier.example',
        'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'GET',
    ]);

    expect($preflight->getStatusCode())->toBe(204)
        ->and($preflight->headers->get('Access-Control-Allow-Methods'))->toBe('GET');
});

it('carries rate-limit headers so clients can behave well without guessing', function (): void {
    $response = $this->getJson('/api/v1/maturity');

    $response->assertOk();
    expect($response->headers->get('X-RateLimit-Limit'))->toBe('60')
        ->and((int) $response->headers->get('X-RateLimit-Remaining'))->toBeGreaterThan(0);
});

it('tolerates garbage query input on the transparency log without erroring', function (): void {
    // ?after_seq=abc must not 500; it is coerced to the safe floor.
    $response = $this->getJson('/api/v1/transparency-log?after_seq=abc');

    $response->assertOk();
    $response->assertJsonPath('after_seq', 0);

    $negative = $this->getJson('/api/v1/transparency-log?after_seq=-50');
    $negative->assertOk();
    $negative->assertJsonPath('after_seq', 0);
});
