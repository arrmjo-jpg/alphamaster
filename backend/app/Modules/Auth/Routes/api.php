<?php

declare(strict_types=1);

use App\Modules\Auth\Controllers\Api\AuthController;
use App\Modules\Auth\Controllers\Api\MfaController;
use App\Modules\Auth\Enums\TokenAbility;
use Illuminate\Support\Facades\Route;

$accessAbilities = implode(',', TokenAbility::accessAbilities());
$enrolAbilities = $accessAbilities.','.TokenAbility::MFA_ENROL->value;

Route::prefix('v1/auth')->group(function () use ($accessAbilities, $enrolAbilities): void {
    // Public: brute-force protection is applied inside the controller, driven by
    // the security.* settings rather than a fixed middleware limit.
    Route::post('/login', [AuthController::class, 'login'])->name('api.auth.login');
    Route::post('/mfa/challenge', [AuthController::class, 'mfaChallenge'])->name('api.auth.mfa.challenge');

    // A fully signed-in identity. An enrolment token is explicitly not enough here,
    // so an administrator mid-enrolment cannot read or act as themselves yet.
    Route::middleware(['auth:sanctum', 'ability:'.$accessAbilities, 'active'])->group(function (): void {
        Route::post('/logout', [AuthController::class, 'logout'])->name('api.auth.logout');
        Route::get('/me', [AuthController::class, 'me'])->name('api.auth.me');

        Route::get('/mfa', [MfaController::class, 'status'])->name('api.auth.mfa.status');
        Route::delete('/mfa', [MfaController::class, 'disable'])->name('api.auth.mfa.disable');
    });

    // Enrolment is the one place an mfa:enrol token is accepted, alongside ordinary
    // access tokens so that a regular user can enrol voluntarily.
    Route::middleware(['auth:sanctum', 'ability:'.$enrolAbilities, 'active'])->group(function (): void {
        Route::post('/mfa/enrol', [MfaController::class, 'enrol'])->name('api.auth.mfa.enrol');
        Route::post('/mfa/verify', [MfaController::class, 'verify'])->name('api.auth.mfa.verify');
    });
});
