<?php

declare(strict_types=1);

namespace App\Modules\Core\Providers;

use App\Modules\Core\Audit\AuditRecorder;
use App\Modules\Core\Backup\ConfigurationPortability;
use App\Modules\Core\Cache\CacheNamespace;
use App\Modules\Core\Cache\CacheNamespaceRegistry;
use App\Modules\Core\Cache\PlatformCache;
use App\Modules\Core\Contracts\AuditRecorderContract;
use App\Modules\Core\Contracts\EdgeCacheContract;
use App\Modules\Core\Contracts\PlatformCacheContract;
use App\Modules\Core\Delivery\NullEdgeCache;
use App\Modules\Core\Http\Cache\HttpCacheProfile;
use App\Modules\Core\Http\Cache\HttpCacheProfileRegistry;
use App\Modules\Core\Http\Cache\ResponseCacheTags;
use App\Modules\Core\MediaAnalysis\MediaAnalyzerContract;
use App\Modules\Core\MediaAnalysis\NullMediaAnalyzer;
use App\Modules\Core\Services\RateLimitPolicy;
use App\Modules\Core\Support\ClientUrlPolicy;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Validator as ValidationContext;

class CoreServiceProvider extends ServiceProvider
{
    /**
     * Register any module services.
     */
    public function register(): void
    {
        // The registry of who contributes to a configuration export. Core owns the
        // envelope; each module registers its own section, because no module may reach
        // into another's store and no single place is allowed to import them all.
        $this->app->singleton(ConfigurationPortability::class);

        // One cache entry point for the whole platform (ADR 0035). A singleton
        // because the key builder is stateless and the generation lookup benefits
        // from not being reconstructed per call.
        $this->app->singleton(PlatformCacheContract::class, PlatformCache::class);

        // Which cache namespaces exist (ADR 0052). Core declares the platform's own; a
        // module that owns cached data registers its namespaces against this singleton
        // from its own provider, so adding one never means editing Core.
        $this->app->singleton(CacheNamespaceRegistry::class, static function (): CacheNamespaceRegistry {
            $registry = new CacheNamespaceRegistry;
            $registry->register(...CacheNamespace::cases());

            return $registry;
        });

        // The audit trail, which every module writes to (ADR 0037).
        $this->app->singleton(AuditRecorderContract::class, AuditRecorder::class);

        // The edge cache (ADR 0053). Bound only if nothing else is: Integration binds the
        // configured CDN driver, and without it every invalidation answers not_configured.
        $this->app->singletonIf(EdgeCacheContract::class, NullEdgeCache::class);

        // The media analyzer (ADR 0054). Bound only if nothing else is: Integration binds the
        // configured provider, and without it every analysis is refused as not configured.
        $this->app->singletonIf(MediaAnalyzerContract::class, NullMediaAnalyzer::class);

        // How public responses may be cached (ADR 0036, ADR 0053 §2). Core declares the
        // profile its own anonymous configuration endpoints use; a module registers its own
        // against this singleton.
        $this->app->singleton(HttpCacheProfileRegistry::class, static function (): HttpCacheProfileRegistry {
            $registry = new HttpCacheProfileRegistry;
            $registry->register(new HttpCacheProfile(
                name: HttpCacheProfile::PUBLIC_CONFIGURATION,
                // A minute in the browser, which cannot be purged; five at the edge, which
                // is purged when the configuration changes.
                browserMaxAge: 60,
                edgeMaxAge: 300,
                staleWhileRevalidate: 60,
                // A day of the last good copy while the origin is failing: configuration is
                // what a client needs to render anything at all.
                staleIfError: 86400,
                localized: true,
            ));

            return $registry;
        });

        // One per request: the tags the response being built will carry to the edge.
        $this->app->scoped(ResponseCacheTags::class);
    }

    /**
     * Bootstrap any module services.
     */
    public function boot(): void
    {
        // Core owns the audit trail, which every module writes to (ADR 0037).
        $this->loadMigrationsFrom(dirname(__DIR__).'/Database/Migrations');

        $this->registerRoutes();
        $this->registerRateLimiters();
        $this->registerClientUrlRules();
    }

    /**
     * Validation rules for addresses an operator hands to clients (ClientUrlPolicy).
     *
     * Registered by name, so a setting definition declares them as the string rules it
     * already publishes, and a refusal says which part of the policy an address failed
     * rather than only that it is invalid.
     *
     * `client_page_url` checks one web page address. `redirect_uri_list` checks a list of
     * sign-in return addresses: a list, each entry usable, none repeated.
     */
    protected function registerClientUrlRules(): void
    {
        Validator::extend('client_page_url', static function (string $attribute, mixed $value, array $parameters, ValidationContext $validator): bool {
            $problem = ClientUrlPolicy::forCurrentEnvironment()->pageUrlProblem($value);

            if ($problem === null) {
                return true;
            }

            $validator->setCustomMessages([
                $attribute.'.client_page_url' => self::clientUrlMessage('validation.client_url.address', $problem),
            ]);

            return false;
        });

        Validator::extend('redirect_uri_list', static function (string $attribute, mixed $value, array $parameters, ValidationContext $validator): bool {
            if (! is_array($value) || ! array_is_list($value)) {
                $validator->setCustomMessages([
                    $attribute.'.redirect_uri_list' => (string) __('validation.client_url.list'),
                ]);

                return false;
            }

            $policy = ClientUrlPolicy::forCurrentEnvironment();
            $seen = [];

            foreach ($value as $position => $uri) {
                $problem = $policy->redirectUriProblem($uri);

                if ($problem === null && in_array($uri, $seen, true)) {
                    $problem = 'duplicate';
                }

                if ($problem !== null) {
                    $validator->setCustomMessages([
                        $attribute.'.redirect_uri_list' => self::clientUrlMessage('validation.client_url.entry', $problem, ['position' => $position + 1]),
                    ]);

                    return false;
                }

                $seen[] = $uri;
            }

            return true;
        });
    }

    /**
     * @param  array<string, int|string>  $replace
     */
    private static function clientUrlMessage(string $key, string $problem, array $replace = []): string
    {
        return (string) __($key, $replace + ['problem' => (string) __('validation.client_url.problem.'.$problem)]);
    }

    /**
     * Register module routes.
     */
    protected function registerRoutes(): void
    {
        $apiRouteFile = __DIR__.'/../Routes/api.php';

        if (file_exists($apiRouteFile)) {
            Route::prefix('api')
                ->middleware(['api'])
                ->group($apiRouteFile);
        }
    }

    /**
     * Define one named limiter per rate-limit class.
     *
     * Composite in two senses. A class is a bucket shared by endpoints of similar
     * cost, so a caller cannot sweep every endpoint by spending a separate
     * allowance on each — and unrelated classes do not compete, so a burst of
     * reads cannot exhaust the budget for writes.
     *
     * An authenticated request is charged against two dimensions at once: the
     * user, which bounds a compromised token however many addresses it comes
     * from, and the address, which bounds a botnet however many accounts it
     * holds. Laravel enforces every Limit returned here, so the tighter of the
     * two binds.
     *
     * The bucket is the class, never the URI. `/media/{id}` therefore cannot
     * create one bucket per resource, which would let a caller with many ids
     * spend an unbounded total.
     *
     * Public so a test that rebuilds the limiter singleton can re-register the
     * definitions without also re-registering routes.
     */
    public function registerRateLimiters(): void
    {
        $policy = $this->app->make(RateLimitPolicy::class);

        foreach (RateLimitPolicy::classes() as $class) {
            RateLimiter::for($class, function (Request $request) use ($policy, $class): array {
                $decay = $policy->decayMinutes($class);
                $user = $request->user();

                // Hashed for the same reason LoginThrottle hashes its own: a cache
                // store is not a place to keep client addresses in clear.
                $ip = Limit::perMinutes($decay, $policy->maxAttemptsForIp($class))
                    ->by($class.':ip:'.sha1((string) $request->ip()));

                if ($user === null) {
                    // Anonymous: the address is the only identity there is, so it
                    // carries the per-identity allowance rather than the looser one.
                    return [
                        Limit::perMinutes($decay, $policy->maxAttempts($class))
                            ->by($class.':ip:'.sha1((string) $request->ip())),
                    ];
                }

                // The user rather than the token: a limit bounds what a principal
                // may do, and keying on the token would let one user multiply their
                // allowance by minting more. TransientToken has no identifier at
                // all, so a token key would not always exist.
                return [
                    Limit::perMinutes($decay, $policy->maxAttempts($class))
                        ->by($class.':u:'.$user->getAuthIdentifier()),
                    $ip,
                ];
            });
        }
    }
}
