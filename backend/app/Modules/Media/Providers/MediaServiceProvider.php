<?php

declare(strict_types=1);

namespace App\Modules\Media\Providers;

use App\Modules\Core\Contracts\ProfileAvatarContract;
use App\Modules\Core\MediaAnalysis\MediaAnalysisContract;
use App\Modules\Media\Console\ProbeMediaDurationsCommand;
use App\Modules\Media\Contracts\CdnUrlResolverContract;
use App\Modules\Media\Contracts\MediaScannerContract;
use App\Modules\Media\Contracts\MediaServiceContract;
use App\Modules\Media\Contracts\MediaStorageContract;
use App\Modules\Media\Enums\MediaType;
use App\Modules\Media\Services\Analysis\MediaAnalysisPolicy;
use App\Modules\Media\Services\Analysis\MediaAnalysisService;
use App\Modules\Media\Services\MediaAccessResolver;
use App\Modules\Media\Services\MediaService;
use App\Modules\Media\Services\Processing\FfprobeInspector;
use App\Modules\Media\Services\Processing\GenericFileProcessor;
use App\Modules\Media\Services\Processing\TimedMediaProcessor;
use App\Modules\Media\Services\ProcessorRegistry;
use App\Modules\Media\Services\ProfileAvatars;
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

        // The profile picture, as the modules that present an account ask for it: they may
        // not depend on this module, so Core declares the question (ADR 0051 §4).
        $this->app->singleton(ProfileAvatars::class);
        $this->app->singleton(ProfileAvatarContract::class, fn ($app): ProfileAvatars => $app->make(ProfileAvatars::class));

        // The null scanner reports NOT_SCANNED rather than CLEAN. Swapping in a real
        // scanner is this one binding.
        $this->app->singleton(MediaScannerContract::class, NullMediaScanner::class);

        // Images and documents yield what can be read without decoding them. Video and
        // audio are read with ffprobe, which the image installs, for the duration media
        // analysis limits are checked against (ADR 0054); where the binary is absent the
        // duration is recorded as unavailable rather than invented.
        $this->app->singleton(ProcessorRegistry::class, fn ($app): ProcessorRegistry => new ProcessorRegistry([
            new GenericFileProcessor(MediaType::IMAGE),
            new TimedMediaProcessor(MediaType::VIDEO, $app->make(FfprobeInspector::class)),
            new TimedMediaProcessor(MediaType::AUDIO, $app->make(FfprobeInspector::class)),
            new GenericFileProcessor(MediaType::DOCUMENT),
        ]));

        // Policies are registered by the modules that attach media. Media ships none,
        // and denies private access when no policy answers for a type.
        $this->app->singleton(MediaAccessResolver::class, fn (): MediaAccessResolver => new MediaAccessResolver);

        $this->app->singleton(MediaServiceContract::class, MediaService::class);

        // Media analysis (ADR 0054): declared in Core so any module can call it, carried out
        // here. Registering it starts nothing — an analysis exists only when a consumer
        // asks for one, and nothing in the intake pipeline does.
        $this->app->singleton(MediaAnalysisPolicy::class);
        $this->app->singleton(MediaAnalysisContract::class, MediaAnalysisService::class);
    }

    /**
     * Bootstrap module services.
     */
    public function boot(): void
    {
        $this->loadMigrationsFrom(dirname(__DIR__).'/Database/Migrations');
        $this->registerRoutes();

        if ($this->app->runningInConsole()) {
            $this->commands([ProbeMediaDurationsCommand::class]);
        }
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
