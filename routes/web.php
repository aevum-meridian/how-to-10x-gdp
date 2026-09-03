<?php

/**
 * SPDX-License-Identifier: LicenseRef-AVL-2.0
 *
 * The human-facing surface: the ethical dashboard (DOCUMENT 10.3).
 * It reads the same sources the API serves, so the human page can
 * never say something the machine surface does not.
 *
 * © Maher
 */

declare(strict_types=1);

use App\Http\Controllers\DashboardController;
use Illuminate\Support\Facades\Route;

Route::get('/', DashboardController::class)->name('dashboard');
