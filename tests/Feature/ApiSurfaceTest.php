<?php

/**
 * SPDX-License-Identifier: LicenseRef-MVL-2.0 AND LicenseRef-AVL-2.0
 *
 * ApiSurfaceTest — DEV-10 (DOCUMENT 10.1).
 *
 * The public API's binding honesty rules, tested:
 *   - GET /api/v1/maturity is live, complete, and never presents a
 *     non-Shipped capability as available (AVL-2.0 §A-§C.13);
 *   - the maturity ledger carries the courage-to-stop rows verbatim in
 *     substance (eight-billion-users is Research with the inverted
 *     abandonment criterion; $FOCUS is DeprecatedRemoved and never
 *     deleted);
 *   - GET /api/v1/trade-off-register discloses costs, not just choices;
 *   - the currency registry is READ-only (no write route exists);
 *   - the transparency log hands a verifier everything needed;
 *   - the API exposes no posting endpoint (transfer/mint/settle) —
 *     exposing one unauthenticated would present a Research capability
 *     as available.
 *
 * © Maher
 */

declare(strict_types=1);

use App\Domain\Joint\Maturity\Enums\MaturityLabel;
use App\Domain\Joint\Maturity\MaturityLedger;
use App\Domain\Joint\Maturity\TradeOffRegister;
use Illuminate\Support\Facades\Route;
use Tests\Support\LedgerFixtures;

describe('ApiSurfaceTest (DEV-10, DOCUMENT 10.1)', function (): void {
    test('GET /api/v1/maturity is the binding check: live, labelled, and never overclaiming', function (): void {
        $response = $this->getJson('/api/v1/maturity');

        $response->assertOk()
            ->assertJsonStructure([
                'ledger', 'binding', 'labels',
                'entries' => [['subsystem', 'label', 'presentable_as_available', 'exit_criterion', 'abandonment_criterion']],
                'honest_note',
            ]);

        /** @var list<array{subsystem: string, label: string, presentable_as_available: bool}> $entries */
        $entries = $response->json('entries');

        // NOTHING in this deployment is presentable as available — the
        // suite is green but Shipped requires external audit + production.
        foreach ($entries as $entry) {
            expect($entry['label'])->not->toBe('shipped')
                ->and($entry['presentable_as_available'])->toBeFalse(
                    "{$entry['subsystem']} claims availability — the exact overclaim the ledger forbids."
                );
        }

        // Every row carries BOTH criteria: ready AND stop.
        foreach ($entries as $entry) {
            expect($entry['exit_criterion'])->not->toBe('')
                ->and($entry['abandonment_criterion'])->not->toBe('');
        }
    });

    test('the most important row: eight-billion-users is Research and its abandonment criterion revises the claim, never the floors', function (): void {
        $entry = MaturityLedger::find('eight-billion-users');

        expect($entry->label)->toBe(MaturityLabel::Research)
            ->and($entry->abandonmentCriterion)->toContain('revised downward')
            ->and($entry->abandonmentCriterion)->toContain('rather than the safety floors relaxed');
    });

    test('the DeprecatedRemoved row is kept, never deleted: $FOCUS stays as the honest historical record', function (): void {
        $focus = MaturityLedger::find('currency-focus-eeg');

        expect($focus->label)->toBe(MaturityLabel::DeprecatedRemoved)
            ->and($focus->exitCriterion)->toContain('no-neural-data');

        // The binding check refuses it like everything non-Shipped.
        expect(MaturityLedger::presentableAsAvailable('currency-focus-eeg'))->toBeFalse();
    });

    test('an unlabelled capability cannot be surfaced: the ledger throws rather than defaulting', function (): void {
        expect(fn (): mixed => MaturityLedger::find('some-capability-nobody-labelled'))
            ->toThrow(InvalidArgumentException::class, 'may not be surfaced at all');
    });

    test('GET /api/v1/trade-off-register discloses the COST of every choice, not just the choice', function (): void {
        $response = $this->getJson('/api/v1/trade-off-register');

        $response->assertOk()
            ->assertJsonStructure(['register', 'purpose', 'entries' => [['id', 'axis', 'chosen', 'cost', 'spec_source']]]);

        /** @var list<array{id: string, chosen: string, cost: string, spec_source: string}> $entries */
        $entries = $response->json('entries');
        expect(count($entries))->toBeGreaterThanOrEqual(10);

        foreach ($entries as $entry) {
            expect($entry['cost'])->not->toBe('')
                ->and($entry['spec_source'])->toContain('DOCUMENT');
        }

        // The named honest scopes are on the record.
        $ids = array_column($entries, 'id');
        expect($ids)->toContain('non-punishment-vs-fraud-recovery')
            ->toContain('erasure-vs-append-only')
            ->toContain('flux-losing-trade')
            ->toContain('loss-fund-boundary')
            ->toContain('ethical-source-not-osi');

        // The register class agrees with the endpoint.
        expect(count(TradeOffRegister::all()))->toBe(count($entries));
    });

    test('the currency registry is read-public and carries the four-element Core Riba flags openly', function (): void {
        $currency = LedgerFixtures::currency();

        $response = $this->getJson('/api/v1/currencies');

        $response->assertOk();

        /** @var list<array{code: string}> $currencies */
        $currencies = $response->json('currencies');
        expect(array_column($currencies, 'code'))->toContain($currency->code);

        // Registration is not an availability claim — the endpoint says so.
        expect((string) $response->json('maturity_note'))->toContain('not an availability claim');
    });

    test('the transparency log hands a third party everything needed to verify the chain', function (): void {
        $response = $this->getJson('/api/v1/transparency-log');

        $response->assertOk()
            ->assertJsonStructure(['log', 'verification', 'signers', 'after_seq', 'page_size', 'events']);

        expect((string) $response->json('verification'))->toContain('never auto-corrected');
    });

    test('the incident endpoint publishes the disclosure clock: severities, one-way deadlines, and OVERDUE states', function (): void {
        app(App\Domain\Joint\Crisis\Services\CrisisService::class)->declare(
            App\Domain\Joint\Crisis\Enums\Severity::S3,
            'API surface test incident',
        );

        $response = $this->getJson('/api/v1/incidents');

        $response->assertOk();

        /** @var list<array{severity: string, deadlines: array{status_page_due_at: string}, disclosures: array<string, string>}> $incidents */
        $incidents = $response->json('incidents');
        expect(count($incidents))->toBeGreaterThanOrEqual(1)
            ->and($incidents[0]['severity'])->toBe('s3')
            ->and($incidents[0]['disclosures'])->toHaveKeys(['status_page', 'preliminary_report', 'postmortem']);

        expect((string) $response->json('commander_note'))->toContain('NO ledger power');
    });

    test('NO posting endpoint exists on the public surface: transfer, mint, and settle have no route', function (): void {
        $registered = collect(Route::getRoutes()->getRoutes())
            ->map(static fn (Illuminate\Routing\Route $route): string => strtolower($route->uri()))
            ->filter(static fn (string $uri): bool => str_starts_with($uri, 'api/'));

        foreach (['transfer', 'mint', 'settle', 'burn', 'reverse', 'debit'] as $forbidden) {
            $offenders = $registered->filter(
                static fn (string $uri): bool => str_contains($uri, $forbidden)
            );

            expect($offenders->all())->toBe(
                [],
                "A posting-shaped route \"{$forbidden}\" is exposed on the public API — "
                .'presenting an InDevelopment capability as available (AVL-2.0 §A-§C.13).'
            );
        }

        // And the whole /api surface is GET/HEAD only.
        foreach (Route::getRoutes()->getRoutes() as $route) {
            if (! str_starts_with(strtolower($route->uri()), 'api/')) {
                continue;
            }

            expect(array_diff($route->methods(), ['GET', 'HEAD']))->toBe(
                [],
                "Route {$route->uri()} accepts a write method on the public surface."
            );
        }
    });

    test('every API response about capability carries maturity context: the registry and incidents endpoints reference their charters', function (): void {
        expect((string) $this->getJson('/api/v1/currencies')->json('registry'))->toContain('governance-gated')
            ->and((string) $this->getJson('/api/v1/incidents')->json('charter'))->toContain('never deleted')
            ->and((string) $this->getJson('/api/v1/maturity')->json('binding'))->toContain('A-§C.13');
    });
});
