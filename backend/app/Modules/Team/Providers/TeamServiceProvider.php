<?php

declare(strict_types=1);

namespace App\Modules\Team\Providers;

use App\Modules\Authorization\Services\PermissionCatalogue;
use App\Modules\Core\Contracts\PublicUrlContract;
use App\Modules\Core\Http\Cache\HttpCacheProfile;
use App\Modules\Core\Http\Cache\HttpCacheProfileRegistry;
use App\Modules\Core\Media\MediaReferenceRegistry;
use App\Modules\Core\Seo\PublicRoute;
use App\Modules\Core\Seo\Sitemap\SitemapRegistry;
use App\Modules\Core\Seo\StructuredData\StructuredData;
use App\Modules\Core\Translation\TranslationRegistry;
use App\Modules\Team\Enums\TeamPermission;
use App\Modules\Team\Models\TeamMember;
use App\Modules\Team\Seo\PersonSchema;
use App\Modules\Team\Seo\TeamMediaReferences;
use App\Modules\Team\Seo\TeamSitemapSource;
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

        // Search and sharing (ADR 0058): where a profile is published, its sitemap entries, its
        // structured data, and the files it shows.
        $this->callAfterResolving(
            PublicUrlContract::class,
            static fn (PublicUrlContract $urls) => $urls->register(new PublicRoute('team', '/{locale}/team/{slug}')),
        );
        $this->callAfterResolving(
            SitemapRegistry::class,
            fn (SitemapRegistry $sitemap) => $sitemap->register($this->app->make(TeamSitemapSource::class)),
        );
        $this->callAfterResolving(
            StructuredData::class,
            fn (StructuredData $schema) => $schema->register($this->app->make(PersonSchema::class)),
        );
        $this->callAfterResolving(
            MediaReferenceRegistry::class,
            fn (MediaReferenceRegistry $references) => $references->register($this->app->make(TeamMediaReferences::class)),
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
