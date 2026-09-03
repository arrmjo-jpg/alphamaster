<?php

declare(strict_types=1);

use App\Modules\Auth\Controllers\Api\AuthController;
use App\Modules\Auth\Controllers\Api\MfaController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1/auth')->group(function (): void {
    // Public: brute-force protection is applied inside the controller, driven by
    // the security.* settings rather than a fixed middleware limit.
    Route::post('/login', [AuthController::class, 'login'])->name('api.auth.login');
    Route::post('/mfa/challenge', [AuthController::class, 'mfaChallenge'])->name('api.auth.mfa.challenge');

    // Authenticated: any valid token, admin or not. The ability layer is not applied
    // here because these endpoints act on the caller's own identity.
    Route::middleware(['auth:sanctum', 'active'])->group(function (): void {
        Route::post('/logout', [AuthController::class, 'logout'])->name('api.auth.logout');
        Route::get('/me', [AuthController::class, 'me'])->name('api.auth.me');

        Route::get('/mfa', [MfaController::class, 'status'])->name('api.auth.mfa.status');
        Route::post('/mfa/enrol', [MfaController::class, 'enrol'])->name('api.auth.mfa.enrol');
        Route::post('/mfa/verify', [MfaController::class, 'verify'])->name('api.auth.mfa.verify');
        Route::delete('/mfa', [MfaController::class, 'disable'])->name('api.auth.mfa.disable');
    });
});
