<?php

declare(strict_types=1);

namespace App\Modules\Notification\Providers;

use App\Modules\Notification\Contracts\NotifierContract;
use App\Modules\Notification\Contracts\PreferenceResolverContract;
use App\Modules\Notification\Contracts\TemplateRendererContract;
use App\Modules\Notification\Services\Notifier;
use App\Modules\Notification\Services\PreferenceResolver;
use App\Modules\Notification\Services\TemplateRenderer;
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
