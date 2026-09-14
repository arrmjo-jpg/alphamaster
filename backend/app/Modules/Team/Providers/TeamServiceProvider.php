<?php

declare(strict_types=1);

namespace App\Modules\Team\Providers;

use App\Modules\Authorization\Services\PermissionCatalogue;
use App\Modules\Core\Http\Cache\HttpCacheProfile;
use App\Modules\Core\Http\Cache\HttpCacheProfileRegistry;
use App\Modules\Core\Translation\TranslationRegistry;
use App\Modules\Team\Enums\TeamPermission;
use App\Modules\Team\Models\TeamMember;
use App\Modules\Team\Translation\TeamTranslationSource;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * The team directory (ADR 0055), registered against the platform's registries (ADR 0052).
 */
class TeamServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->callAfterResolving(
            PermissionCatalogue::class,
            static fn (PermissionCatalogue $catalogue) => $catalogue->register(TeamPermission::class),
        );

        $this->callAfterResolving(
            HttpCacheProfileRegistry::class,
            static fn (HttpCacheProfileRegistry $profiles) => $profiles->register(new HttpCacheProfile(
                name: 'team',
                browserMaxAge: 300,
                edgeMaxAge: 3600,
                staleWhileRevalidate: 60,
                staleIfError: 86400,
                localized: true,
            )),
        );
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(dirname(__DIR__).'/Database/Migrations');

        $this->app->make(TranslationRegistry::class)
            ->register($this->app->make(TeamTranslationSource::class));

        Route::bind('member', static fn (string $value) => TeamMember::query()->findOrFail($value));

        Route::prefix('api')
            ->middleware(['api'])
            ->group(dirname(__DIR__).'/Routes/api.php');
    }
}
