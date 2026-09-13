<?php

declare(strict_types=1);

namespace App\Modules\Notification\Providers;

use App\Modules\Core\Contracts\PushDeviceRegistrarContract;
use App\Modules\Core\Translation\TranslationRegistry;
use App\Modules\Notification\Contracts\NotifierContract;
use App\Modules\Notification\Contracts\PreferenceResolverContract;
use App\Modules\Notification\Contracts\TemplateRendererContract;
use App\Modules\Notification\Services\Notifier;
use App\Modules\Notification\Services\PreferenceResolver;
use App\Modules\Notification\Services\PushDeviceRegistrar;
use App\Modules\Notification\Services\TemplateRenderer;
use App\Modules\Notification\Translation\NotificationTemplateTranslationSource;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class NotificationServiceProvider extends ServiceProvider
{
    /**
     * Register module services.
     */
    public function register(): void
    {
        $this->app->singleton(TemplateRendererContract::class, TemplateRenderer::class);
        $this->app->singleton(PreferenceResolverContract::class, PreferenceResolver::class);
        $this->app->singleton(NotifierContract::class, Notifier::class);

        // The one thing outside this module that touches the device registry: signing
        // out, which must stop delivery to a handset the session registered (ADR 0045
        // §5). Declared in Core because Auth may not depend on Notification.
        $this->app->singleton(PushDeviceRegistrarContract::class, PushDeviceRegistrar::class);
    }

    /**
     * Bootstrap module services.
     */
    public function boot(): void
    {
        $this->loadMigrationsFrom(dirname(__DIR__).'/Database/Migrations');
        // What this module has that a person reads, declared to the translation
        // workshop (ADR 0043). Registered here rather than listed centrally, because
        // Core may not import a domain module and a central list would have to.
        $this->app->make(TranslationRegistry::class)
            ->register($this->app->make(NotificationTemplateTranslationSource::class));

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
