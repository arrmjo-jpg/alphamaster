<?php

declare(strict_types=1);

use App\Modules\Auth\Controllers\Api\AuthController;
use App\Modules\Auth\Controllers\Api\EmailVerificationController;
use App\Modules\Auth\Controllers\Api\MfaController;
use App\Modules\Auth\Controllers\Api\PhoneVerificationController;
use App\Modules\Auth\Enums\TokenAbility;
use Illuminate\Support\Facades\Route;

$accessAbilities = implode(',', TokenAbility::accessAbilities());
$enrolAbilities = $accessAbilities.','.TokenAbility::MFA_ENROL->value;
$verifyAbilities = $accessAbilities.','.TokenAbility::EMAIL_VERIFY->value;

Route::prefix('v1/auth')->group(function () use ($accessAbilities, $enrolAbilities, $verifyAbilities): void {
    // Public: brute-force protection is applied inside the controller, driven by
    // the security.* settings rather than a fixed middleware limit.
    Route::post('/login', [AuthController::class, 'login'])->name('api.auth.login');
    Route::post('/mfa/challenge', [AuthController::class, 'mfaChallenge'])->name('api.auth.mfa.challenge');
    Route::post('/mfa/challenge/send', [AuthController::class, 'mfaChallengeSend'])->name('api.auth.mfa.challenge.send');

    // Reached from a mail client, so there is no token to present: the signature is
    // the credential, and `signed` refuses anything this platform did not issue or
    // whose expiry has passed.
    //
    // The route name is the framework's, not this module's. VerifyEmail builds its
    // URL with URL::temporarySignedRoute('verification.verify', ...) and that string
    // is not configurable, so renaming this to match the api.auth.* convention would
    // produce a notification that cannot generate a link.
    Route::get('/email/verify/{id}/{hash}', [EmailVerificationController::class, 'verify'])
        ->middleware('signed')
        ->name('verification.verify');

    // A fully signed-in identity. An enrolment token is explicitly not enough here,
    // so an administrator mid-enrolment cannot read or act as themselves yet.
    Route::middleware(['auth:sanctum', 'ability:'.$accessAbilities, 'active'])->group(function (): void {
        Route::post('/logout', [AuthController::class, 'logout'])->name('api.auth.logout');
        Route::get('/me', [AuthController::class, 'me'])->name('api.auth.me');

        Route::get('/mfa', [MfaController::class, 'status'])->name('api.auth.mfa.status');
        Route::delete('/mfa', [MfaController::class, 'disable'])->name('api.auth.mfa.disable');

        // Confirming your own number. Behind no permission, for the reason email
        // verification is behind none: the caller proves something about themselves,
        // and neither route takes an account identifier, so there is nobody else's
        // number to reach however either is called.
        Route::post('/phone/verify/send', [PhoneVerificationController::class, 'send'])
            ->name('api.auth.phone.verify.send');
        Route::post('/phone/verify', [PhoneVerificationController::class, 'verify'])
            ->name('api.auth.phone.verify');
    });

    // Requesting a verification link is the one place an email:verify token is
    // accepted, alongside ordinary access tokens so that anyone may re-request one.
    //
    // Still authenticated, and still takes no address of its own: the mail goes to the
    // account the credential belongs to, so this cannot be aimed at a stranger's inbox
    // however it is called.
    Route::middleware(['auth:sanctum', 'ability:'.$verifyAbilities, 'active'])->group(function (): void {
        Route::post('/email/verify/send', [EmailVerificationController::class, 'send'])
            ->name('api.auth.email.verify.send');
    });

    // Enrolment is the one place an mfa:enrol token is accepted, alongside ordinary
    // access tokens so that a regular user can enrol voluntarily.
    Route::middleware(['auth:sanctum', 'ability:'.$enrolAbilities, 'active'])->group(function (): void {
        Route::post('/mfa/enrol', [MfaController::class, 'enrol'])->name('api.auth.mfa.enrol');
        Route::post('/mfa/verify', [MfaController::class, 'verify'])->name('api.auth.mfa.verify');
    });
});
