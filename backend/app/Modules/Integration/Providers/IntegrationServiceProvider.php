<?php

declare(strict_types=1);

namespace App\Modules\Integration\Providers;

use App\Modules\Core\Ai\TextGeneratorContract;
use App\Modules\Core\Backup\ConfigurationPortability;
use App\Modules\Core\Contracts\EdgeCacheContract;
use App\Modules\Integration\Backup\ProviderPortability;
use App\Modules\Integration\Contracts\CaptchaVerifierContract;
use App\Modules\Integration\Contracts\PushDispatcherContract;
use App\Modules\Integration\Contracts\SmsDispatcherContract;
use App\Modules\Integration\Contracts\SocialLoginGatewayContract;
use App\Modules\Integration\Services\AiManager;
use App\Modules\Integration\Services\CaptchaManager;
use App\Modules\Integration\Services\CaptchaVerifier;
use App\Modules\Integration\Services\CdnEdgeCache;
use App\Modules\Integration\Services\CdnManager;
use App\Modules\Integration\Services\PushDispatcher;
use App\Modules\Integration\Services\PushManager;
use App\Modules\Integration\Services\SmsDispatcher;
use App\Modules\Integration\Services\SmsManager;
use App\Modules\Integration\Services\SocialLoginGateway;
use App\Modules\Integration\Services\SocialLoginManager;
use App\Modules\Integration\Services\TextGenerator;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class IntegrationServiceProvider extends ServiceProvider
{
    /**
     * Register module services.
     */
    public function register(): void
    {
        $this->app->singleton(SmsManager::class, fn ($app): SmsManager => new SmsManager($app));

        // Consumers depend on the dispatcher, not the manager: selecting a provider,
        // falling back and recording usage are part of sending, not the caller's job.
        $this->app->singleton(SmsDispatcherContract::class, SmsDispatcher::class);

        $this->app->singleton(CaptchaManager::class, fn ($app): CaptchaManager => new CaptchaManager($app));

        // Same division for captcha: a consumer asks for a verdict, and provider
        // selection and usage recording stay here. Falling back does not, and the
        // verifier says why — a token belongs to the vendor that minted it.
        $this->app->singleton(CaptchaVerifierContract::class, CaptchaVerifier::class);

        $this->app->singleton(AiManager::class, fn ($app): AiManager => new AiManager($app));

        // The AI seam is declared in Core rather than here, because its first consumer
        // is Localization — whose dependency rule names Core and the framework and
        // nothing else (ADR 0044 §2). Integration binds it, as the module that owns
        // vendors, and nothing outside this file knows which vendor answers.
        $this->app->singleton(TextGeneratorContract::class, TextGenerator::class);

        $this->app->singleton(PushManager::class, fn ($app): PushManager => new PushManager($app));

        // Push is a transport like SMS, so the division is the same: a channel asks
        // for delivery, and provider selection, failover and usage recording stay here
        // (ADR 0045 §2). Firebase is a driver behind this and nothing more.
        $this->app->singleton(PushDispatcherContract::class, PushDispatcher::class);

        $this->app->singleton(SocialLoginManager::class, fn ($app): SocialLoginManager => new SocialLoginManager($app));

        // The same division as captcha: Auth asks for an identity, and deciding whether a
        // provider is usable, redeeming the code and recording the attempt stay here. No
        // failover, because an authorization code belongs to the vendor that issued it.
        $this->app->singleton(SocialLoginGatewayContract::class, SocialLoginGateway::class);

        $this->app->singleton(CdnManager::class, fn ($app): CdnManager => new CdnManager($app));

        // The edge cache seam is Core's, so every module — and every domain module to come —
        // invalidates the edge without knowing Integration exists (ADR 0053). Integration
        // binds it, as the module that owns vendors.
        $this->app->singleton(CdnEdgeCache::class);
        $this->app->singleton(EdgeCacheContract::class, fn ($app): EdgeCacheContract => $app->make(CdnEdgeCache::class));
    }

    /**
     * Bootstrap module services.
     */
    public function boot(): void
    {
        // This module's share of a portable configuration export (ADR 0039). Registered
        // here rather than listed centrally, because Core may not import a domain module
        // and a central list would have to.
        $this->app->make(ConfigurationPortability::class)
            ->register($this->app->make(ProviderPortability::class));
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
