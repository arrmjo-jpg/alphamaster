<?php

declare(strict_types=1);

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
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
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
        // Append core middleware to API group
        $middleware->api(append: [
            ForceJsonResponse::class,
            AttachRequestContext::class,
            SetLocale::class,
        ]);

        // Register route middleware aliases
        $middleware->alias([
            'admin' => EnsureUserIsAdmin::class,
            'active' => EnsureAccountActive::class,
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
                    'message' => 'The given data was invalid.',
                    'details' => $e->errors(),
                ],
            ], 422);
        });

        $exceptions->render(function (AuthenticationException $e, Request $request): JsonResponse {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'UNAUTHENTICATED',
                    'message' => 'Authentication is required to access this resource.',
                ],
            ], 401);
        });

        $exceptions->render(function (AuthorizationException $e, Request $request): JsonResponse {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'FORBIDDEN',
                    'message' => $e->getMessage() ?: 'This action is unauthorized.',
                ],
            ], 403);
        });

        $exceptions->render(function (ModelNotFoundException $e, Request $request): JsonResponse {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'NOT_FOUND',
                    'message' => 'The requested resource was not found.',
                ],
            ], 404);
        });

        $exceptions->render(function (NotFoundHttpException $e, Request $request): JsonResponse {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'NOT_FOUND',
                    'message' => 'The requested route or resource could not be found.',
                ],
            ], 404);
        });

        $exceptions->render(function (MethodNotAllowedHttpException $e, Request $request): JsonResponse {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'METHOD_NOT_ALLOWED',
                    'message' => 'The HTTP method is not allowed for this route.',
                ],
            ], 405);
        });

        $exceptions->render(function (HttpException $e, Request $request): JsonResponse {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'HTTP_ERROR',
                    'message' => $e->getMessage() ?: 'An HTTP error occurred.',
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
                            ? 'An unexpected server error occurred.'
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
