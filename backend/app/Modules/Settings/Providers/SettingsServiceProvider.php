<?php

declare(strict_types=1);

namespace App\Modules\Settings\Providers;

use App\Modules\Settings\Console\SynchroniseSettingsCommand;
use App\Modules\Settings\Contracts\SettingServiceInterface;
use App\Modules\Settings\Definitions\Catalogues\AuthCatalogue;
use App\Modules\Settings\Definitions\Catalogues\BrandingCatalogue;
use App\Modules\Settings\Definitions\Catalogues\GeneralCatalogue;
use App\Modules\Settings\Definitions\Catalogues\LocalizationCatalogue;
use App\Modules\Settings\Definitions\Catalogues\MailCatalogue;
use App\Modules\Settings\Definitions\Catalogues\RateLimitCatalogue;
use App\Modules\Settings\Definitions\Catalogues\SecurityCatalogue;
use App\Modules\Settings\Definitions\SettingCatalogue;
use App\Modules\Settings\Definitions\SettingRegistry;
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

        // The registry is a singleton because it is the catalogue, not a query: it is
        // populated once at boot and read many times per request.
        $this->app->singleton(SettingRegistry::class, function (): SettingRegistry {
            $registry = new SettingRegistry;

            foreach ($this->catalogues() as $catalogue) {
                $registry->registerCatalogue(new $catalogue);
            }

            return $registry;
        });
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

        if ($this->app->runningInConsole()) {
            $this->commands([SynchroniseSettingsCommand::class]);
        }
    }

    /**
     * The catalogues whose definitions make up the registry.
     *
     * Listed explicitly rather than discovered by scanning: a catalogue is a class
     * someone wrote and registered, which is the line between an extension point and
     * a plugin system (ADR 0033). A module that acquires settings of its own adds its
     * catalogue here or registers it against the singleton.
     *
     * @return array<int, class-string<SettingCatalogue>>
     */
    protected function catalogues(): array
    {
        return [
            GeneralCatalogue::class,
            LocalizationCatalogue::class,
            BrandingCatalogue::class,
            MailCatalogue::class,
            AuthCatalogue::class,
            SecurityCatalogue::class,
            RateLimitCatalogue::class,
        ];
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
