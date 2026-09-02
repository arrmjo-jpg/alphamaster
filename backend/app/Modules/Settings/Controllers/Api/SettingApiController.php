<?php

declare(strict_types=1);

namespace App\Modules\Settings\Controllers\Api;

use App\Modules\Core\Controllers\BaseApiController;
use App\Modules\Settings\Contracts\SettingServiceInterface;
use Illuminate\Http\JsonResponse;

class SettingApiController extends BaseApiController
{
    public function __construct(
        protected SettingServiceInterface $settingService
    ) {}

    /**
     * List all public settings grouped by group name.
     * Response payload contains only public keys and typed values (zero metadata exposure).
     */
    public function index(): JsonResponse
    {
        $data = $this->settingService->getPublicSettings();

        return response()->json([
            'success' => true,
            'data' => $data,
        ]);
    }

    /**
     * Get public settings for a specific group.
     */
    public function show(string $group): JsonResponse
    {
        $data = $this->settingService->getPublicGroup($group);

        return response()->json([
            'success' => true,
            'data' => $data,
        ]);
    }
}
