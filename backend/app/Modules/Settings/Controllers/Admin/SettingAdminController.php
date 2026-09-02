<?php

declare(strict_types=1);

namespace App\Modules\Settings\Controllers\Admin;

use App\Modules\Core\Controllers\BaseApiController;
use App\Modules\Settings\Contracts\SettingServiceInterface;
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
        $data = $this->settingService->getAdminAll();

        return response()->json([
            'success' => true,
            'data' => $data,
        ]);
    }

    /**
     * Get all settings in a specific group with admin details and masked secrets.
     */
    public function show(string $group): JsonResponse
    {
        $data = $this->settingService->getAdminGroup($group);

        return response()->json([
            'success' => true,
            'data' => $data,
        ]);
    }

    /**
     * Batch update an array of settings within a group atomically.
     * Expected contract: { "settings": { "key1": "val1", "key2": val2 } }
     */
    public function update(UpdateGroupSettingsRequest $request, string $group): JsonResponse
    {
        $payload = $request->validated()['settings'];

        try {
            $updated = $this->settingService->updateGroup($group, $payload);

            return response()->json([
                'success' => true,
                'data' => [
                    'group' => $group,
                    'updated' => $updated,
                ],
                'message' => "Settings for group [{$group}] successfully updated.",
            ]);
        } catch (InvalidArgumentException $e) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'INVALID_SETTING_VALUE',
                    'message' => $e->getMessage(),
                ],
            ], 422);
        }
    }
}
