<?php

declare(strict_types=1);

namespace App\Modules\Localization\Providers;

use App\Modules\Core\Contracts\LocaleResolverInterface;
use App\Modules\Localization\Services\LocaleResolver;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class LocalizationServiceProvider extends ServiceProvider
{
    /**
     * Register module services.
     */
    public function register(): void
    {
        // Bind the Core LocaleResolverInterface to Localization's concrete LocaleResolver
        $this->app->singleton(LocaleResolverInterface::class, LocaleResolver::class);
        $this->app->singleton(LocaleResolver::class);
    }

    /**
     * Bootstrap module services.
     */
    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../Database/Migrations');
        $this->registerRoutes();
    }

    /**
     * Register module routes.
     */
    protected function registerRoutes(): void
    {
        $apiRouteFile = __DIR__.'/../Routes/api.php';

        if (file_exists($apiRouteFile)) {
            Route::prefix('api')
                ->middleware(['api'])
                ->group($apiRouteFile);
        }
    }
}
