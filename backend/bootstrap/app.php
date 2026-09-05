<?php

declare(strict_types=1);

use App\Modules\Authorization\Middleware\EnsurePermission;
use App\Modules\Core\Middleware\AttachRequestContext;
use App\Modules\Core\Middleware\EnsureAccountActive;
use App\Modules\Core\Middleware\EnsureUserIsAdmin;
use App\Modules\Core\Middleware\ForceJsonResponse;
use App\Modules\Core\Middleware\SetLocale;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Exceptions\MissingAbilityException;
use Laravel\Sanctum\Http\Middleware\CheckAbilities;
use Laravel\Sanctum\Http\Middleware\CheckForAnyAbility;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Locale negotiation is global, so it runs before routing and before
        // authentication. As API-group middleware it could not reach a request
        // that matched no route at all, and Laravel's middleware priority hoists
        // Authenticate ahead of the group, so 404, 405 and 401 were all answered
        // in the configuration default with no Content-Language header — a
        // response declaring a language it had never negotiated (ADR 0015).
        $middleware->append(SetLocale::class);

        // Append core middleware to API group
        $middleware->api(append: [
            ForceJsonResponse::class,
            AttachRequestContext::class,
        ]);

        // Register route middleware aliases
        $middleware->alias([
            'admin' => EnsureUserIsAdmin::class,
            'permission' => EnsurePermission::class,
            'active' => EnsureAccountActive::class,
            'ability' => CheckForAnyAbility::class,
            'abilities' => CheckAbilities::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        $exceptions->render(function (ValidationException $e, Request $request): JsonResponse {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'VALIDATION_ERROR',
                    'message' => __('api.error.validation_failed'),
                    'details' => $e->errors(),
                ],
            ], 422);
        });

        $exceptions->render(function (AuthenticationException $e, Request $request): JsonResponse {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'UNAUTHENTICATED',
                    'message' => __('api.error.unauthenticated'),
                ],
            ], 401);
        });

        $exceptions->render(function (AuthorizationException $e, Request $request): JsonResponse {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'FORBIDDEN',
                    'message' => $e->getMessage() ?: __('api.error.forbidden'),
                ],
            ], 403);
        });

        $exceptions->render(function (MissingAbilityException $e, Request $request): JsonResponse {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'FORBIDDEN',
                    'message' => __('api.error.missing_ability'),
                ],
            ], 403);
        });

        $exceptions->render(function (ModelNotFoundException $e, Request $request): JsonResponse {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'NOT_FOUND',
                    'message' => __('api.error.model_not_found'),
                ],
            ], 404);
        });

        $exceptions->render(function (NotFoundHttpException $e, Request $request): JsonResponse {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'NOT_FOUND',
                    'message' => __('api.error.route_not_found'),
                ],
            ], 404);
        });

        $exceptions->render(function (MethodNotAllowedHttpException $e, Request $request): JsonResponse {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'METHOD_NOT_ALLOWED',
                    'message' => __('api.error.method_not_allowed'),
                ],
            ], 405);
        });

        // Registered before the generic HttpException handler, which would otherwise
        // answer a 429 with HTTP_ERROR, the framework's English sentence, and none of
        // the headers the limiter produced.
        $exceptions->render(function (ThrottleRequestsException $e, Request $request): JsonResponse {
            $headers = $e->getHeaders();
            $retryAfter = (int) ($headers['Retry-After'] ?? 0);

            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'TOO_MANY_ATTEMPTS',
                    'message' => __('api.error.too_many_attempts', ['seconds' => $retryAfter]),
                    'details' => ['retry_after' => $retryAfter],
                ],
            ], 429, $headers);
        });

        $exceptions->render(function (HttpException $e, Request $request): JsonResponse {
            $code = match ($e->getStatusCode()) {
                401 => 'UNAUTHENTICATED',
                403 => 'FORBIDDEN',
                404 => 'NOT_FOUND',
                405 => 'METHOD_NOT_ALLOWED',
                default => 'HTTP_ERROR',
            };

            return response()->json([
                'success' => false,
                'error' => [
                    'code' => $code,
                    'message' => $e->getMessage() ?: __('api.error.http_error'),
                ],
            ], $e->getStatusCode());
        });

        $exceptions->render(function (Throwable $e, Request $request): ?JsonResponse {
            if ($request->is('api/*') || $request->expectsJson()) {
                $response = [
                    'success' => false,
                    'error' => [
                        'code' => 'INTERNAL_SERVER_ERROR',
                        'message' => app()->isProduction()
                            ? __('api.error.server_error')
                            : $e->getMessage(),
                    ],
                ];

                if (! app()->isProduction()) {
                    $response['error']['debug'] = [
                        'exception' => get_class($e),
                        'file' => $e->getFile(),
                        'line' => $e->getLine(),
                        'trace' => collect($e->getTrace())->take(5)->toArray(),
                    ];
                }

                return response()->json($response, 500);
            }

            return null;
        });
    })->create();
