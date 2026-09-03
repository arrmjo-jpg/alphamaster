<?php

declare(strict_types=1);

namespace App\Modules\User\Providers;

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
    public function register(): void {}

    /**
     * Bootstrap module services.
     */
    public function boot(): void {}
}
