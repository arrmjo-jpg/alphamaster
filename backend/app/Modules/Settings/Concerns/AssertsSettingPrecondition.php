<?php

declare(strict_types=1);

namespace App\Modules\Settings\Concerns;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The `If-Match` contract every write to a settings group carries (ADR 0038).
 *
 * Extracted the moment a second controller needed it. Two copies of a concurrency
 * check drift, and the way they drift is that one of them is relaxed for a reason that
 * looked local — which is how "every write requires a precondition" quietly becomes
 * "most writes do".
 *
 * Requires the using class to expose `$settingService` and the base controller's
 * `errorResponse`.
 */
trait AssertsSettingPrecondition
{
    /**
     * Refuse a write that was not built on the group's current state.
     *
     * Returns a response to send, or null when the write may proceed.
     *
     * A missing precondition is refused rather than waved through. Accepting one would
     * leave every client that had not been updated silently overwriting, which is the
     * behaviour this exists to end — reachable by omitting a header. 428 says the
     * request needs a precondition; 412 says the one it carried is stale.
     */
    protected function assertPrecondition(Request $request, string $group): ?JsonResponse
    {
        $presented = trim((string) $request->header('If-Match'), '"');

        if ($presented === '') {
            return $this->errorResponse(
                'PRECONDITION_REQUIRED',
                'api.error.settings.precondition_required',
                null,
                428,
            );
        }

        $current = $this->settingService->groupVersion($group);

        if (! hash_equals($current, $presented)) {
            // The current state travels with the refusal, so a client can show what
            // changed rather than only reporting that it failed.
            return $this->errorResponse(
                'SETTING_VERSION_CONFLICT',
                'api.error.settings.version_conflict',
                ['current_version' => $current],
                412,
            );
        }

        return null;
    }
}
