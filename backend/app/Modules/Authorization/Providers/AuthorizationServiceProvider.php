<?php

declare(strict_types=1);

namespace App\Modules\Authorization\Providers;

use App\Modules\Authorization\Contracts\AdminRbacContract;
use App\Modules\Authorization\Models\Permission;
use App\Modules\Authorization\Models\Role;
use App\Modules\Authorization\Services\AdminEffectiveGrants;
use App\Modules\Authorization\Services\AdminRbac;
use App\Modules\Authorization\Translation\RoleTranslationSource;
use App\Modules\Core\Contracts\EffectiveGrants;
use App\Modules\Core\Translation\TranslationRegistry;
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

        // The Core-facing view of the same boundary, so a module that may not import
        // Authorization can still ask what an account may do (ADR 0028). Bound here
        // because this module owns the answer.
        $this->app->singleton(EffectiveGrants::class, AdminEffectiveGrants::class);
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
        // What this module has that a person reads, declared to the translation
        // workshop (ADR 0043). Registered here rather than listed centrally, because
        // Core may not import a domain module and a central list would have to.
        $this->app->make(TranslationRegistry::class)
            ->register($this->app->make(RoleTranslationSource::class));

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
