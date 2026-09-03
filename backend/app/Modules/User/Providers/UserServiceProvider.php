<?php

declare(strict_types=1);

namespace App\Modules\User\Providers;

use App\Modules\User\Contracts\AccountTypeManagerContract;
use App\Modules\User\Services\AccountTypeManager;
use Illuminate\Support\ServiceProvider;

class UserServiceProvider extends ServiceProvider
{
    /**
     * Register module services.
     *
     * The User module currently contributes the authenticatable model that
     * config/auth.php resolves; it owns no bindings, migrations or routes of its
     * own yet. The users table lives in the framework's base migration.
     */
    public function register(): void
    {
        $this->app->singleton(AccountTypeManagerContract::class, AccountTypeManager::class);
    }

    /**
     * Bootstrap module services.
     */
    public function boot(): void
    {
        $this->loadMigrationsFrom(dirname(__DIR__).'/Database/Migrations');
    }
}
