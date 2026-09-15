<?php

declare(strict_types=1);

namespace App\Modules\Pages\Providers;

use App\Modules\Authorization\Services\PermissionCatalogue;
use App\Modules\Core\Contracts\PublicUrlContract;
use App\Modules\Core\Http\Cache\HttpCacheProfile;
use App\Modules\Core\Http\Cache\HttpCacheProfileRegistry;
use App\Modules\Core\Media\MediaReferenceRegistry;
use App\Modules\Core\Seo\PublicRoute;
use App\Modules\Core\Seo\Sitemap\SitemapRegistry;
use App\Modules\Core\Seo\StructuredData\StructuredData;
use App\Modules\Core\Translation\TranslationRegistry;
use App\Modules\Pages\Enums\PagePermission;
use App\Modules\Pages\Seo\PageMediaReferences;
use App\Modules\Pages\Seo\PageSitemapSource;
use App\Modules\Pages\Seo\WebPageSchema;
use App\Modules\Pages\Translation\PageTranslationSource;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * Static pages (ADR 0055). Everything this module contributes to the platform is registered
 * here, against the registries foundation modules expose (ADR 0052), and no foundation module
 * names it.
 */
class PagesServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->callAfterResolving(
            PermissionCatalogue::class,
            static fn (PermissionCatalogue $catalogue) => $catalogue->register(PagePermission::class),
        );

        // Public pages change when an editor saves, and every save purges them, so the edge
        // may keep them for an hour; a browser, which cannot be purged, for five minutes.
        $this->callAfterResolving(
            HttpCacheProfileRegistry::class,
            static fn (HttpCacheProfileRegistry $profiles) => $profiles->register(new HttpCacheProfile(
                name: 'pages',
                browserMaxAge: 300,
                edgeMaxAge: 3600,
                staleWhileRevalidate: 60,
                staleIfError: 86400,
                localized: true,
            )),
        );

        // Search and sharing (ADR 0058): where a page is published, its sitemap entries, its
        // structured data, and the files it shows.
        $this->callAfterResolving(
            PublicUrlContract::class,
            static fn (PublicUrlContract $urls) => $urls->register(new PublicRoute('pages', '/{locale}/pages/{slug}')),
        );
        $this->callAfterResolving(
            SitemapRegistry::class,
            fn (SitemapRegistry $sitemap) => $sitemap->register($this->app->make(PageSitemapSource::class)),
        );
        $this->callAfterResolving(
            StructuredData::class,
            static fn (StructuredData $schema) => $schema->register(new WebPageSchema),
        );
        $this->callAfterResolving(
            MediaReferenceRegistry::class,
            fn (MediaReferenceRegistry $references) => $references->register($this->app->make(PageMediaReferences::class)),
        );
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(dirname(__DIR__).'/Database/Migrations');

        $this->app->make(TranslationRegistry::class)
            ->register($this->app->make(PageTranslationSource::class));

        Route::prefix('api')
            ->middleware(['api'])
            ->group(dirname(__DIR__).'/Routes/api.php');
    }
}
