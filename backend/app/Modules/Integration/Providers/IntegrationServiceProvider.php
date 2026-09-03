<?php

declare(strict_types=1);

namespace App\Modules\Integration\Providers;

use App\Modules\Integration\Contracts\SmsDispatcherContract;
use App\Modules\Integration\Services\SmsDispatcher;
use App\Modules\Integration\Services\SmsManager;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class IntegrationServiceProvider extends ServiceProvider
{
    /**
     * Register module services.
     */
    public function register(): void
    {
        $this->app->singleton(SmsManager::class, fn ($app): SmsManager => new SmsManager($app));

        // Consumers depend on the dispatcher, not the manager: selecting a provider,
        // falling back and recording usage are part of sending, not the caller's job.
        $this->app->singleton(SmsDispatcherContract::class, SmsDispatcher::class);
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
