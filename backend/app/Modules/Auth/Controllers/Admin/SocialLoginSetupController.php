<?php

declare(strict_types=1);

namespace App\Modules\Auth\Controllers\Admin;

use App\Modules\Auth\Services\PasswordRecoveryService;
use App\Modules\Auth\Services\SocialLoginFlow;
use App\Modules\Core\Controllers\BaseApiController;
use App\Modules\Integration\Contracts\SocialLoginGatewayContract;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;

/**
 * What an operator needs to finish setting up social login, and what is still missing
 * (ADR 0050 §10–§12).
 *
 * A read, assembled from what is configured and nothing else. The platform assumes no
 * frontend domain, so it cannot tell an operator which address to register with Google;
 * it can only show the return addresses the operator listed — exactly as a sign-in will
 * match them — and say which of them this environment will actually accept. With nothing
 * configured, this answers with no address at all rather than an example or a guess.
 *
 * The return addresses are the ones to register as authorized redirect URIs on the
 * provider's OAuth client: in this design the provider sends the person back to the
 * client, and the client posts the code to the API.
 *
 * Provider status names missing fields and never their values; no credential is read out.
 */
class SocialLoginSetupController extends BaseApiController
{
    public function __construct(
        private readonly SocialLoginFlow $flow,
        private readonly PasswordRecoveryService $recovery,
        private readonly SocialLoginGatewayContract $gateway,
    ) {}

    /**
     * Social login setup: the return addresses to register with a provider, the reset page, provider status, and what is missing.
     */
    #[Response(200, type: 'array{success: bool, data: array{enabled: bool, registration_enabled: bool, production: bool, ready: bool, redirect_uris: list<array{uri: string, usable: bool, problem: string|null, problem_label: string|null}>, register_in_provider_console: list<string>, password_reset_url: array{value: string|null, usable: bool, problem: string|null, problem_label: string|null}, providers: list<array{key: string, label: string, active: bool, effective: bool, client_id_configured: bool, client_secret_configured: bool, missing: list<string>}>, issues: list<array{code: string, label: string}>}}')]
    #[Response(403, description: 'The caller is not an administrator holding settings.view.')]
    public function show(): JsonResponse
    {
        $redirectUris = array_map(fn (array $entry): array => [
            'uri' => $entry['uri'],
            'usable' => $entry['problem'] === null,
            'problem' => $entry['problem'],
            'problem_label' => $this->problemLabel($entry['problem']),
        ], $this->flow->redirectUris());

        $register = array_values(array_unique(array_map(
            static fn (array $entry): string => $entry['uri'],
            array_filter($redirectUris, static fn (array $entry): bool => $entry['usable']),
        )));

        $resetValue = setting('auth.password_reset_url');
        $resetProblem = $this->recovery->resetPageProblem();
        $providers = $this->gateway->providerStatuses();
        $anyEffective = array_filter($providers, static fn (array $provider): bool => $provider['effective']) !== [];

        $issues = [];

        if ($redirectUris === []) {
            $issues[] = 'no_redirect_uris';
        } elseif (count($register) < count($redirectUris)) {
            $issues[] = 'unusable_redirect_uri';
        }

        if ($resetProblem === 'missing') {
            $issues[] = 'password_reset_url_missing';
        } elseif ($resetProblem !== null) {
            $issues[] = 'password_reset_url_unusable';
        }

        if (! $anyEffective) {
            $issues[] = 'no_effective_provider';
        }

        return $this->successResponse([
            'enabled' => setting('auth.social_login_enabled', false) === true,
            'registration_enabled' => setting('auth.registration_enabled', false) === true,
            'production' => app()->isProduction(),
            'ready' => $register !== [] && $resetProblem === null && $anyEffective,
            'redirect_uris' => $redirectUris,
            'register_in_provider_console' => $register,
            'password_reset_url' => [
                'value' => is_string($resetValue) && $resetValue !== '' ? $resetValue : null,
                'usable' => $resetProblem === null,
                'problem' => $resetProblem,
                'problem_label' => $resetProblem === 'missing' ? null : $this->problemLabel($resetProblem),
            ],
            'providers' => $providers,
            'issues' => array_map(static fn (string $code): array => [
                'code' => $code,
                'label' => (string) __('social_login.setup.issue.'.$code),
            ], $issues),
        ]);
    }

    private function problemLabel(?string $problem): ?string
    {
        return $problem === null ? null : (string) __('validation.client_url.problem.'.$problem);
    }
}
