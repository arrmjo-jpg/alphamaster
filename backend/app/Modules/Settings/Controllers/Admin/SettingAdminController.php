<?php

declare(strict_types=1);

namespace App\Modules\Settings\Controllers\Admin;

use App\Modules\Core\Controllers\BaseApiController;
use App\Modules\Settings\Contracts\SettingServiceInterface;
use App\Modules\Settings\Exceptions\SettingGroupNotFoundException;
use App\Modules\Settings\Exceptions\UnknownSettingKeyException;
use App\Modules\Settings\Requests\UpdateGroupSettingsRequest;
use Illuminate\Http\JsonResponse;
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
            return $this->successResponse($this->settingService->getAdminGroup($group));
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
            $updated = $this->settingService->updateGroup($group, $payload);
        } catch (SettingGroupNotFoundException $e) {
            return $this->errorResponse('SETTING_GROUP_NOT_FOUND', $e->translationKey(), null, 404, $e->translationParameters());
        } catch (UnknownSettingKeyException $e) {
            return $this->errorResponse('SETTING_KEY_NOT_FOUND', $e->translationKey(), null, 404, $e->translationParameters());
        } catch (InvalidArgumentException $e) {
            return $this->errorResponse('INVALID_SETTING_VALUE', $e->getMessage(), null, 422);
        }

        return $this->successResponse(
            data: [
                'group' => $group,
                'updated' => $updated,
            ],
            message: 'api.settings.group_updated',
            replace: ['group' => $group],
        );
    }
}
