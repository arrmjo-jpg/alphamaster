<?php

declare(strict_types=1);

namespace App\Modules\Authorization\Providers;

use App\Modules\Authorization\Contracts\AdminRbacContract;
use App\Modules\Authorization\Models\Permission;
use App\Modules\Authorization\Models\Role;
use App\Modules\Authorization\Services\AdminRbac;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Spatie\Permission\PermissionRegistrar;

class AuthorizationServiceProvider extends ServiceProvider
{
    /**
     * Register module services.
     */
    public function register(): void
    {
        $this->app->singleton(AdminRbacContract::class, AdminRbac::class);
    }

    /**
     * Bootstrap module services.
     */
    public function boot(): void
    {
        // Use the module's own models, which add the owning-module column (ADR 0014).
        $registrar = $this->app->make(PermissionRegistrar::class);
        $registrar->setPermissionClass(Permission::class);
        $registrar->setRoleClass(Role::class);

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
