<?php

declare(strict_types=1);

namespace App\Modules\Core\Providers;

use App\Modules\Core\Services\RateLimitPolicy;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class CoreServiceProvider extends ServiceProvider
{
    /**
     * Register any module services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any module services.
     */
    public function boot(): void
    {
        $this->registerRoutes();
        $this->registerRateLimiters();
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
     */
    protected function registerRateLimiters(): void
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
