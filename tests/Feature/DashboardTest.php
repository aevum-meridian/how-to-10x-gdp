<?php

/**
 * SPDX-License-Identifier: LicenseRef-AVL-2.0
 *
 * DashboardTest (DOCUMENT 10.3 — the ethical dashboard).
 *
 * The human surface is bound to the same sources as the machine surface.
 * These tests hold the rendered page to the DOCUMENT 10.3 obligations:
 * plain-language honesty first, accessible structure, honest currency
 * scopes (including the uncomfortable ones), never an availability
 * overclaim, and a deliberate absence of any money-action UI.
 *
 * The structural accessibility assertions here encode what the expert
 * audit verified in a real browser (pa11y WCAG2AA + axe, light and dark,
 * mobile overflow, keyboard skip-link) so the suite catches regressions
 * without needing a browser.
 *
 * © Maher
 */

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Joint\Maturity\MaturityLedger;

it('serves the ethical dashboard at the root, in plain language, leading with honesty', function (): void {
    $response = $this->get('/');

    $response->assertOk();
    $html = $response->getContent();

    // The honest maturity statement leads — before any capability copy.
    $bannerPos = strpos($html, 'Nothing here is finished.');
    $spinePos = strpos($html, 'can never be taken from you as a punishment');
    expect($bannerPos)->not->toBeFalse()
        ->and($spinePos)->not->toBeFalse()
        ->and($bannerPos)->toBeLessThan($spinePos);

    // It binds itself to the machine surface, visibly.
    $response->assertSee('/api/v1/maturity');
});

it('never overclaims: the page reflects the live ledger and marks nothing as available', function (): void {
    $response = $this->get('/');
    $html = (string) $response->getContent();

    $shipped = 0;
    foreach (MaturityLedger::all() as $entry) {
        if ($entry->label->presentableAsAvailable()) {
            $shipped++;
        }
    }

    // Nothing in this deployment is Shipped, and the page must say so
    // with the live count — not a hardcoded reassurance.
    expect($shipped)->toBe(0)
        ->and($html)->toContain('<strong>0</strong> shipped');

    // Every ledger row appears on the page with its label class.
    foreach (MaturityLedger::all() as $entry) {
        expect($html)->toContain($entry->subsystem)
            ->and($html)->toContain('label--'.$entry->label->value);
    }
});

it('states the honest currency scopes, including the uncomfortable ones', function (): void {
    $response = $this->get('/');

    // DOCUMENT 10.3: "$FLUX is a losing trade to hold idle, $PEG+ is
    // experimental, contribution credits can never be taken from you."
    $response->assertSee('losing', escape: false);
    $response->assertSee('experimental', escape: false);
    $response->assertSee('can', escape: false);
    $html = (string) $response->getContent();
    expect($html)->toContain('never be taken from you')
        ->and($html)->toContain('retired')   // $FOCUS stays in the record
        ->and($html)->toContain('$FOCUS');
});

it('is structurally accessible: one h1, landmarks, skip link, labelled tables, lang attribute', function (): void {
    $html = (string) $this->get('/')->getContent();

    expect(substr_count($html, '<h1'))->toBe(1)
        ->and($html)->toContain('<html lang="en"')
        ->and($html)->toContain('class="skip-link"')
        ->and($html)->toContain('<main id="main"')
        ->and(substr_count($html, '<header'))->toBe(1)
        ->and(substr_count($html, '<footer'))->toBe(1)
        ->and($html)->toContain('<caption>')                 // tables are named
        ->and($html)->toContain('scope="col"')               // and header-scoped
        ->and($html)->toContain('name="viewport"')           // mobile-ready
        ->and($html)->toContain('prefers-reduced-motion')    // motion respected
        ->and($html)->toContain('prefers-color-scheme');     // dark mode respected

    // Labels never rely on color alone: each carries a distinct glyph.
    expect($html)->toContain('content: "◐ "')
        ->and($html)->toContain('content: "◯ "')
        ->and($html)->toContain('content: "✕ "');
});

it('offers no money-action UI, and explains that absence instead of hiding it', function (): void {
    $html = (string) $this->get('/')->getContent();

    // No forms, no inputs, no buttons: there is nothing to click that
    // could imply an available transactional capability.
    expect($html)->not->toContain('<form')
        ->and($html)->not->toContain('<input')
        ->and($html)->not->toContain('<button')
        ->and(stripos($html, 'no sign-up'))->not->toBeFalse(); // the absence is explained…

    expect($html)->toContain('deliberate, not an oversight'); // …honestly.
});

it('links every claim to its machine-readable source of truth', function (): void {
    $html = (string) $this->get('/')->getContent();

    foreach ([
        '/api/v1/maturity',
        '/api/v1/trade-off-register',
        '/api/v1/currencies',
        '/api/v1/transparency-log',
        '/api/v1/incidents',
        '/api/v1/openapi.json',
    ] as $uri) {
        expect($html)->toContain('href="'.$uri.'"');
        $this->get($uri)->assertOk();
    }
});

it('discloses every trade-off with its cost on the human surface too', function (): void {
    $html = (string) $this->get('/')->getContent();

    expect($html)->toContain('The honest cost:');
    foreach (\App\Domain\Joint\Maturity\TradeOffRegister::all() as $tradeOff) {
        expect($html)->toContain(e($tradeOff->axis));
    }
});

it('does not claim to be open source and names the real licenses', function (): void {
    $html = (string) $this->get('/')->getContent();

    // DOCUMENT 10.7: not "open source"; the license names are stated.
    expect($html)->toContain('LicenseRef-MVL-2.0')
        ->and($html)->toContain('LicenseRef-AVL-2.0')
        ->and($html)->toContain('does not claim otherwise');
});
