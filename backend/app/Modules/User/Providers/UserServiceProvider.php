<?php

declare(strict_types=1);

namespace App\Modules\User\Providers;

use App\Modules\User\Console\CreateFirstAdministratorCommand;
use App\Modules\User\Contracts\AccountTypeManagerContract;
use App\Modules\User\Contracts\SocialIdentityRegistryContract;
use App\Modules\User\Services\AccountTypeManager;
use App\Modules\User\Services\SocialIdentityRegistry;
use Illuminate\Support\ServiceProvider;

class UserServiceProvider extends ServiceProvider
{
    /**
     * Register module services.
     *
     * The User module contributes the authenticatable model that config/auth.php
     * resolves, the promotion workflow, and the social identities an account holds. The
     * users table lives in the framework's base migration.
     */
    public function register(): void
    {
        $this->app->singleton(AccountTypeManagerContract::class, AccountTypeManager::class);

        // Declared here because the table is this module's; Auth consumes it to sign
        // people in, and this module never learns that Auth exists (ADR 0050).
        $this->app->singleton(SocialIdentityRegistryContract::class, SocialIdentityRegistry::class);
    }

    /**
     * Bootstrap module services.
     */
    public function boot(): void
    {
        $this->loadMigrationsFrom(dirname(__DIR__).'/Database/Migrations');

        if ($this->app->runningInConsole()) {
            $this->commands([CreateFirstAdministratorCommand::class]);
        }
    }
}
