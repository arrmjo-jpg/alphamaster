<?php

declare(strict_types=1);

namespace App\Modules\Media\Providers;

use App\Modules\Media\Contracts\CdnUrlResolverContract;
use App\Modules\Media\Contracts\MediaScannerContract;
use App\Modules\Media\Contracts\MediaServiceContract;
use App\Modules\Media\Contracts\MediaStorageContract;
use App\Modules\Media\Enums\MediaType;
use App\Modules\Media\Services\MediaAccessResolver;
use App\Modules\Media\Services\MediaService;
use App\Modules\Media\Services\Processing\GenericFileProcessor;
use App\Modules\Media\Services\ProcessorRegistry;
use App\Modules\Media\Services\Scanning\NullMediaScanner;
use App\Modules\Media\Services\SettingsCdnUrlResolver;
use App\Modules\Media\Services\Storage\DiskMediaStorage;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class MediaServiceProvider extends ServiceProvider
{
    /**
     * Register module services.
     */
    public function register(): void
    {
        $this->app->singleton(MediaStorageContract::class, DiskMediaStorage::class);
        $this->app->singleton(CdnUrlResolverContract::class, SettingsCdnUrlResolver::class);

        // The null scanner reports NOT_SCANNED rather than CLEAN. Swapping in a real
        // scanner is this one binding.
        $this->app->singleton(MediaScannerContract::class, NullMediaScanner::class);

        // Only the processors this environment can actually run are registered.
        // Thumbnailing needs gd or imagick and video metadata needs ffprobe; neither
        // is installed, so those types resolve to no processor rather than to a stub
        // that would report values it never derived.
        $this->app->singleton(ProcessorRegistry::class, fn (): ProcessorRegistry => new ProcessorRegistry([
            new GenericFileProcessor(MediaType::IMAGE),
            new GenericFileProcessor(MediaType::VIDEO),
            new GenericFileProcessor(MediaType::AUDIO),
            new GenericFileProcessor(MediaType::DOCUMENT),
        ]));

        // Policies are registered by the modules that attach media. Media ships none,
        // and denies private access when no policy answers for a type.
        $this->app->singleton(MediaAccessResolver::class, fn (): MediaAccessResolver => new MediaAccessResolver);

        $this->app->singleton(MediaServiceContract::class, MediaService::class);
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
