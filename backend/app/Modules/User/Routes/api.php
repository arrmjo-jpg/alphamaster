<?php

declare(strict_types=1);

use App\Modules\User\Controllers\Api\ProfileController;
use Illuminate\Support\Facades\Route;

/*
 * The signed-in account's own profile (ADR 0051 §4).
 *
 * Abilities are named literally: this module may not depend on Auth, and a route file is
 * not a class the architecture rules can see. Every route acts on the caller and takes no
 * account identifier.
 */
Route::prefix('v1/profile')
    ->middleware(['auth:sanctum', 'ability:admin:access,user:access', 'active'])
    ->group(function (): void {
        Route::get('/', [ProfileController::class, 'show'])->name('api.profile.show');
        Route::patch('/', [ProfileController::class, 'update'])->name('api.profile.update');
        Route::put('/password', [ProfileController::class, 'password'])->name('api.profile.password');
        Route::put('/links', [ProfileController::class, 'links'])->name('api.profile.links');
    });
