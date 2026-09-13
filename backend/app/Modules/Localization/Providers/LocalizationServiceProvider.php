<?php

declare(strict_types=1);

namespace App\Modules\Localization\Providers;

use App\Modules\Core\Backup\ConfigurationPortability;
use App\Modules\Core\Contracts\LocaleResolverInterface;
use App\Modules\Core\Translation\TranslationRegistry;
use App\Modules\Localization\Backup\LanguagePortability;
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

        // The one registry of translatable content (ADR 0043). A singleton because a
        // second copy would be a second answer to "what is translatable", and the
        // modules that fill it register against whichever instance boots first.
        $this->app->singleton(TranslationRegistry::class);
    }

    /**
     * Bootstrap module services.
     */
    public function boot(): void
    {
        // This module's share of a portable configuration export (ADR 0039). Registered
        // here rather than listed centrally, because Core may not import a domain module
        // and a central list would have to.
        $this->app->make(ConfigurationPortability::class)
            ->register($this->app->make(LanguagePortability::class));
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
