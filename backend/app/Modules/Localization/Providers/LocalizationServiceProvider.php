<?php

declare(strict_types=1);

namespace App\Modules\Localization\Providers;

use App\Modules\Core\Backup\ConfigurationPortability;
use App\Modules\Core\Contracts\LocaleResolverInterface;
use App\Modules\Core\Translation\TranslationRegistry;
use App\Modules\Localization\Backup\LanguagePortability;
use App\Modules\Localization\Interface\InterfaceCatalogue;
use App\Modules\Localization\Interface\OverlayTranslationLoader;
use App\Modules\Localization\Services\LocaleResolver;
use App\Modules\Localization\Translation\InterfaceTranslationSource;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Translation\Loader;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Event;
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

        // The Interface Translation Catalog (ADR 0049). A singleton because it remembers which
        // cache generation this process's translator loaded under.
        $this->app->singleton(InterfaceCatalogue::class);

        // Laravel's loader, with operators' translations of the API catalogue laid over its
        // files — so `__()`, validation and notification messages are translated in any
        // language without changing how any of them is written.
        $this->app->extend('translation.loader', static fn (Loader $loader, Application $app): Loader => new OverlayTranslationLoader(
            $loader,
            static fn (): InterfaceCatalogue => $app->make(InterfaceCatalogue::class),
        ));
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

        // The interface is translated through the same workshop as content (ADR 0049): one
        // source per catalogue, and nothing registered per key or per language.
        $registry = $this->app->make(TranslationRegistry::class);
        $catalogue = $this->app->make(InterfaceCatalogue::class);

        foreach ($catalogue->names() as $name) {
            $registry->register(new InterfaceTranslationSource(
                $catalogue,
                $name,
                $this->app->make(LocaleResolverInterface::class),
            ));
        }

        // A queue worker's translator outlives a translation being accepted; it reloads before
        // the next job rather than after a restart.
        Event::listen(JobProcessing::class, fn () => $this->app->make(InterfaceCatalogue::class)->refreshTranslator());

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
