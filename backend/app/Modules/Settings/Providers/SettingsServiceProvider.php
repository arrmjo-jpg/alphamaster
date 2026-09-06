<?php

declare(strict_types=1);

namespace App\Modules\Settings\Providers;

use App\Modules\Core\Backup\ConfigurationPortability;
use App\Modules\Core\Contracts\RetentionPolicyContract;
use App\Modules\Settings\Backup\SettingsPortability;
use App\Modules\Settings\Console\SynchroniseSettingsCommand;
use App\Modules\Settings\Contracts\SettingServiceInterface;
use App\Modules\Settings\Definitions\Catalogues\AuthCatalogue;
use App\Modules\Settings\Definitions\Catalogues\BrandingCatalogue;
use App\Modules\Settings\Definitions\Catalogues\GeneralCatalogue;
use App\Modules\Settings\Definitions\Catalogues\LocalizationCatalogue;
use App\Modules\Settings\Definitions\Catalogues\MailCatalogue;
use App\Modules\Settings\Definitions\Catalogues\OperationsCatalogue;
use App\Modules\Settings\Definitions\Catalogues\RateLimitCatalogue;
use App\Modules\Settings\Definitions\Catalogues\SecurityCatalogue;
use App\Modules\Settings\Definitions\SettingCatalogue;
use App\Modules\Settings\Definitions\SettingRegistry;
use App\Modules\Settings\Secrets\MailPasswordVerifier;
use App\Modules\Settings\Secrets\SecretVerifierRegistry;
use App\Modules\Settings\Services\SettingService;
use App\Modules\Settings\Services\SettingsRetentionPolicy;
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

        // Core asks how long the trail is kept and may not depend on Settings to find
        // out (ADR 0037). Settings owns the answer, so Settings binds it — the same
        // direction LocaleResolverInterface already runs in.
        $this->app->singleton(RetentionPolicyContract::class, SettingsRetentionPolicy::class);

        // The registry is a singleton because it is the catalogue, not a query: it is
        // populated once at boot and read many times per request.
        $this->app->singleton(SettingRegistry::class, function (): SettingRegistry {
            $registry = new SettingRegistry;

            foreach ($this->catalogues() as $catalogue) {
                $registry->registerCatalogue(new $catalogue);
            }

            return $registry;
        });

        // Which credentials can be checked before they are committed (ADR 0038). A
        // singleton for the same reason the catalogue is one, and deliberately sparse:
        // most secrets have nothing to call, and an empty entry here is the expected
        // case rather than an omission.
        $this->app->singleton(SecretVerifierRegistry::class, function (): SecretVerifierRegistry {
            $verifiers = new SecretVerifierRegistry;

            $verifiers->register($this->app->make(MailPasswordVerifier::class));

            return $verifiers;
        });
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        // This module's share of a portable configuration export (ADR 0039). Registered
        // here rather than listed centrally, because Core may not import a domain module
        // and a central list would have to.
        $this->app->make(ConfigurationPortability::class)
            ->register($this->app->make(SettingsPortability::class));
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
            OperationsCatalogue::class,
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
