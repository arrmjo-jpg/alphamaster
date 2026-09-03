<?php

declare(strict_types=1);

namespace App\Modules\Settings\Providers;

use App\Modules\Settings\Contracts\SettingServiceInterface;
use App\Modules\Settings\Services\SettingService;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class SettingsServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        // Load helpers
        $helperPath = dirname(__DIR__).'/helpers.php';
        if (file_exists($helperPath)) {
            require_once $helperPath;
        }

        // Bind contract to singleton implementation
        $this->app->singleton(SettingServiceInterface::class, SettingService::class);
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        // Load module migrations
        $this->loadMigrationsFrom(dirname(__DIR__).'/Database/Migrations');

        // Load module routes
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
