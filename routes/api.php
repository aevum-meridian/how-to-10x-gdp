<?php

/**
 * SPDX-License-Identifier: LicenseRef-MVL-2.0 AND LicenseRef-AVL-2.0
 *
 * /api/v1 — DOCUMENT 10.1, the versioned, honest public interface.
 *
 * What is exposed here is the READ surface: the maturity ledger (the
 * binding check every other surface must consult), the Trade-off
 * Register, the currency registry, the transparency log, and the
 * incident disclosure clock. All public, all rate-limited.
 *
 * What is deliberately NOT here, and why (Sidq over surface area):
 * transfer/mint/settlement endpoints require the GAS adapter in front
 * of Sanctum/Passport with holder-authorization tokens — that
 * authenticated surface is InDevelopment (see /api/v1/maturity,
 * subsystem public-api-v1) and exposing it unauthenticated would
 * present a Research capability as available (AVL-2.0 §A-§C.13).
 * The posting paths exist and are invariant-gated in the domain
 * services; the HTTP faces arrive when the auth stack does.
 *
 * © Maher
 */

declare(strict_types=1);

use App\Http\Controllers\Api\V1\CurrencyRegistryController;
use App\Http\Controllers\Api\V1\IncidentDisclosureController;
use App\Http\Controllers\Api\V1\MaturityController;
use App\Http\Controllers\Api\V1\OpenApiController;
use App\Http\Controllers\Api\V1\TradeOffRegisterController;
use App\Http\Controllers\Api\V1\TransparencyLogController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->middleware('throttle:60,1')->group(function (): void {
    // The binding check every other surface must consult (DOCUMENT 3.4).
    Route::get('/maturity', MaturityController::class);

    // The machine-readable Trade-off Register (DOCUMENT 10.1).
    Route::get('/trade-off-register', TradeOffRegisterController::class);

    // The currency registry: read public; write governance-gated (I11).
    Route::get('/currencies', CurrencyRegistryController::class);

    // The transparency log: public, verifiable (DOCUMENT 7.1).
    Route::get('/transparency-log', TransparencyLogController::class);

    // The disclosure clock: public promises, publicly checkable (8.1).
    Route::get('/incidents', IncidentDisclosureController::class);

    // The single source of truth for the API surface (DOCUMENT 10.1);
    // maturity labels read live from the ledger, so it cannot drift.
    Route::get('/openapi.json', OpenApiController::class);
});
