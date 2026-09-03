<?php

declare(strict_types=1);

namespace App\Modules\Auth\Providers;

use App\Modules\Auth\Contracts\AuthServiceContract;
use App\Modules\Auth\Contracts\MfaManagerContract;
use App\Modules\Auth\Enums\MfaType;
use App\Modules\Auth\Services\AuthService;
use App\Modules\Auth\Services\Mfa\SmsOtpMethod;
use App\Modules\Auth\Services\Mfa\TotpMethod;
use App\Modules\Auth\Services\MfaManager;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use PragmaRX\Google2FA\Google2FA;

class AuthServiceProvider extends ServiceProvider
{
    /**
     * Register module services.
     */
    public function register(): void
    {
        $this->app->singleton(Google2FA::class, fn (): Google2FA => new Google2FA);

        $this->app->singleton(TotpMethod::class);
        $this->app->singleton(SmsOtpMethod::class);

        // Methods are registered by type, so a future WebAuthn or OTP driver is added
        // here and nothing in the challenge flow changes (ADR 0013).
        $this->app->singleton(MfaManagerContract::class, fn ($app): MfaManager => new MfaManager([
            MfaType::TOTP->value => $app->make(TotpMethod::class),
            MfaType::SMS_OTP->value => $app->make(SmsOtpMethod::class),
        ]));

        $this->app->singleton(AuthServiceContract::class, AuthService::class);
    }

    /**
     * Bootstrap module services.
     */
    public function boot(): void
    {
        $this->loadMigrationsFrom(dirname(__DIR__).'/Database/Migrations');
        $this->registerRoutes();
    }

    /**
     * Register module routes.
     */
    protected function registerRoutes(): void
    {
        $apiRouteFile = dirname(__DIR__).'/Routes/api.php';

        if (file_exists($apiRouteFile)) {
            Route::prefix('api')
                ->middleware(['api'])
                ->group($apiRouteFile);
        }
    }
}
