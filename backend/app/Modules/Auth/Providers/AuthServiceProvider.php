<?php

declare(strict_types=1);

namespace App\Modules\Auth\Providers;

use App\Modules\Auth\Console\PruneOneTimeCodesCommand;
use App\Modules\Auth\Contracts\AuthServiceContract;
use App\Modules\Auth\Contracts\MfaManagerContract;
use App\Modules\Auth\Enums\MfaType;
use App\Modules\Auth\Services\AuthService;
use App\Modules\Auth\Services\ConfirmedMfaEnrolmentStatus;
use App\Modules\Auth\Services\ConfirmedMfaSmsRecipientResolver;
use App\Modules\Auth\Services\Mfa\SmsOtpMethod;
use App\Modules\Auth\Services\Mfa\TotpMethod;
use App\Modules\Auth\Services\MfaManager;
use App\Modules\Auth\Services\PasswordRecoveryService;
use App\Modules\Auth\Support\AuthCookie;
use App\Modules\Core\Contracts\MfaEnrolmentStatus;
use App\Modules\Core\Contracts\SmsRecipientResolverInterface;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Contracts\Auth\CanResetPassword;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\Sanctum;
use LogicException;
use PragmaRX\Google2FA\Google2FA;

class AuthServiceProvider extends ServiceProvider
{
    /**
     * Register module services.
     */
    public function register(): void
    {
        $this->app->singleton(Google2FA::class, fn (): Google2FA => new Google2FA);

        $this->app->singleton(TotpMethod::class);
        $this->app->singleton(SmsOtpMethod::class);

        // Methods are registered by type, so a future WebAuthn or OTP driver is added
        // here and nothing in the challenge flow changes (ADR 0013).
        $this->app->singleton(MfaManagerContract::class, fn ($app): MfaManager => new MfaManager([
            MfaType::TOTP->value => $app->make(TotpMethod::class),
            MfaType::SMS_OTP->value => $app->make(SmsOtpMethod::class),
        ]));

        $this->app->singleton(AuthServiceContract::class, AuthService::class);

        // Auth owns the confirmed number, so it supplies the resolver Notification
        // consumes through the Core contract.
        $this->app->singleton(SmsRecipientResolverInterface::class, ConfirmedMfaSmsRecipientResolver::class);

        // The administrative user list needs to know whether an account is protected,
        // and the User module may not import this one. Core declares the question;
        // this is the module that owns the answer, so it binds it — the same
        // direction EffectiveGrants runs in.
        $this->app->singleton(MfaEnrolmentStatus::class, ConfirmedMfaEnrolmentStatus::class);
    }

    /**
     * Bootstrap module services.
     */
    public function boot(): void
    {
        $this->loadMigrationsFrom(dirname(__DIR__).'/Database/Migrations');
        $this->registerRoutes();
        $this->registerCookieTransport();
        $this->registerPasswordResetLinks();

        if ($this->app->runningInConsole()) {
            $this->commands([PruneOneTimeCodesCommand::class]);
        }
    }

    /**
     * Let an access token arrive in a cookie as well as in a header (ADR 0042).
     *
     * A published extension point: Sanctum's guard consults this callback before
     * falling back to bearerToken(). What it returns is looked up as an ordinary
     * personal access token, so currentAccessToken() is a real PersonalAccessToken
     * and tokenCan() reads real abilities — which is the entire reason for doing it
     * this way rather than through EnsureFrontendRequestsAreStateful, whose
     * TransientToken answers true to every ability check and would silently delete
     * the perimeter of ADR 0012 and the scoping of ADR 0013.
     *
     * Cookie first, then bearer. The order matters only when both are present, and
     * then the browser's own credential is the more specific statement of who is
     * calling. Bearer is untouched and remains fully supported.
     */
    protected function registerCookieTransport(): void
    {
        Sanctum::getAccessTokenFromRequestUsing(static function (Request $request): ?string {
            $fromCookie = $request->cookie(AuthCookie::NAME);

            if (is_string($fromCookie) && $fromCookie !== '') {
                return $fromCookie;
            }

            return $request->bearerToken();
        });
    }

    /**
     * Where a password reset link points (ADR 0050 §12).
     *
     * The framework's notification would otherwise build a URL to a `password.reset`
     * route on this API, which does not exist and would not be where a person enters a
     * new password. The client that does is named by an operator, and no domain is
     * assumed: the link goes to `auth.password_reset_url` carrying the token and the
     * address it was issued for.
     */
    protected function registerPasswordResetLinks(): void
    {
        ResetPassword::createUrlUsing(static function (CanResetPassword $user, string $token): string {
            // Judged again at the moment the link is built. A mail with a link to nowhere,
            // or to an address this environment refuses, is not sent at all.
            $target = app(PasswordRecoveryService::class)->resetPageUrl()
                ?? throw new LogicException('A password reset link was requested while no usable reset page is configured.');

            $separator = str_contains($target, '?') ? '&' : '?';

            return $target.$separator.http_build_query([
                'token' => $token,
                'email' => $user->getEmailForPasswordReset(),
            ], '', '&', PHP_QUERY_RFC3986);
        });
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
