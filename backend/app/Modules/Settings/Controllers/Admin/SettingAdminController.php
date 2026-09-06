<?php

declare(strict_types=1);

namespace App\Modules\Settings\Controllers\Admin;

use App\Modules\Core\Controllers\BaseApiController;
use App\Modules\Settings\Contracts\SettingServiceInterface;
use App\Modules\Settings\Exceptions\SettingGroupNotFoundException;
use App\Modules\Settings\Exceptions\UnknownSettingKeyException;
use App\Modules\Settings\Requests\UpdateGroupSettingsRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

class SettingAdminController extends BaseApiController
{
    public function __construct(
        protected SettingServiceInterface $settingService
    ) {}

    /**
     * List all settings grouped by group with admin details and masked secrets.
     */
    public function index(): JsonResponse
    {
        return $this->successResponse($this->settingService->getAdminAll());
    }

    /**
     * Get all settings in a specific group with admin details and masked secrets.
     */
    public function show(string $group): JsonResponse
    {
        try {
            $settings = $this->settingService->getAdminGroup($group);
            $version = $this->settingService->groupVersion($group);

            // Handed back both ways: as an ETag for a client that speaks HTTP
            // conditional requests, and in meta for one that does not (ADR 0038).
            return $this->successResponse($settings, meta: ['version' => $version])
                ->header('ETag', '"'.$version.'"');
        } catch (SettingGroupNotFoundException $e) {
            return $this->errorResponse('SETTING_GROUP_NOT_FOUND', $e->translationKey(), null, 404, $e->translationParameters());
        }
    }

    /**
     * Batch update an array of settings within a group atomically.
     * Expected contract: { "settings": { "key1": "val1", "key2": val2 } }
     *
     * An unknown group or key is a 404 (settings are provisioned, not created here);
     * a value that cannot be represented in the setting's declared type is a 422.
     */
    public function update(UpdateGroupSettingsRequest $request, string $group): JsonResponse
    {
        /** @var array<string, mixed> $payload */
        $payload = $request->validated()['settings'];

        try {
            $precondition = $this->assertPrecondition($request, $group);

            if ($precondition !== null) {
                return $precondition;
            }

            $updated = $this->settingService->updateGroup($group, $payload);
        } catch (SettingGroupNotFoundException $e) {
            return $this->errorResponse('SETTING_GROUP_NOT_FOUND', $e->translationKey(), null, 404, $e->translationParameters());
        } catch (UnknownSettingKeyException $e) {
            return $this->errorResponse('SETTING_KEY_NOT_FOUND', $e->translationKey(), null, 404, $e->translationParameters());
        } catch (InvalidArgumentException $e) {
            return $this->errorResponse('INVALID_SETTING_VALUE', $e->getMessage(), null, 422);
        }

        $version = $this->settingService->groupVersion($group);

        return $this->successResponse(
            data: [
                'group' => $group,
                'updated' => $updated,
            ],
            message: 'api.settings.group_updated',
            replace: ['group' => $group],
            meta: ['version' => $version],
        )->header('ETag', '"'.$version.'"');
    }

    /**
     * Refuse a write that was not built on the group's current state (ADR 0038).
     *
     * Returns a response to send, or null when the write may proceed.
     *
     * A missing precondition is refused rather than waved through. Accepting one
     * would leave every client that had not been updated silently overwriting, which
     * is the behaviour this exists to end — reachable by omitting a header. 428 says
     * the request needs a precondition; 412 says the one it carried is stale.
     */
    private function assertPrecondition(Request $request, string $group): ?JsonResponse
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
