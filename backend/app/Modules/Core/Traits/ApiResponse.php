<?php

declare(strict_types=1);

namespace App\Modules\Core\Traits;

use Illuminate\Http\JsonResponse;
use Illuminate\Pagination\LengthAwarePaginator;

trait ApiResponse
{
    /**
     * Return a standardized JSON success response.
     *
     * @param  array<string, mixed>  $meta
     * @param  array<string, mixed>  $replace  Values for the message's placeholders.
     */
    protected function successResponse(
        mixed $data = null,
        ?string $message = null,
        int $statusCode = 200,
        array $meta = [],
        array $replace = []
    ): JsonResponse {
        $response = [
            'success' => true,
        ];

        if ($message !== null) {
            $response['message'] = $this->localizeMessage($message, $replace);
        }

        if ($data !== null) {
            $response['data'] = $data;
        }

        if (! empty($meta)) {
            $response['meta'] = $meta;
        }

        return response()->json($response, $statusCode);
    }

    /**
     * Return a standardized JSON error response.
     *
     * @param  array<string, mixed>  $replace  Values for the message's placeholders.
     */
    protected function errorResponse(
        string $code,
        string $message,
        mixed $details = null,
        int $statusCode = 400,
        array $replace = []
    ): JsonResponse {
        $error = [
            // `code` is contract and is never localized (ADR 0031).
            'code' => $code,
            'message' => $this->localizeMessage($message, $replace),
        ];

        if ($details !== null) {
            $error['details'] = $details;
        }

        return response()->json([
            'success' => false,
            'error' => $error,
        ], $statusCode);
    }

    /**
     * Return a standardized JSON paginated response.
     */
    protected function paginatedResponse(
        LengthAwarePaginator $paginator,
        ?string $message = null,
        int $statusCode = 200
    ): JsonResponse {
        return $this->successResponse(
            data: $paginator->items(),
            message: $message,
            statusCode: $statusCode,
            meta: [
                'pagination' => [
                    'current_page' => $paginator->currentPage(),
                    'per_page' => $paginator->perPage(),
                    'total' => $paginator->total(),
                    'last_page' => $paginator->lastPage(),
                    'has_more_pages' => $paginator->hasMorePages(),
                ],
            ]
        );
    }

    /**
     * Resolve a message a caller expressed as a translation key.
     *
     * Localization sits here rather than at the call sites, per ADR 0015: a
     * caller names what it means, never which language it means it in. A string
     * that is no known key comes back unchanged, which is what still happens to
     * the messages carried on domain exceptions — they are sentences today, and
     * become keys when that layer is converted.
     *
     * @param  array<string, mixed>  $replace
     */
    private function localizeMessage(string $message, array $replace): string
    {
        $translated = __($message, $replace);

        return is_string($translated) ? $translated : $message;
    }
}
