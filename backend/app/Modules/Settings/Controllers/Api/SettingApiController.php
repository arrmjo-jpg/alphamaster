<?php

declare(strict_types=1);

namespace App\Modules\Settings\Controllers\Api;

use App\Modules\Core\Controllers\BaseApiController;
use App\Modules\Settings\Contracts\SettingServiceInterface;
use App\Modules\Settings\Exceptions\SettingGroupNotFoundException;
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
        return $this->successResponse($this->settingService->getPublicSettings());
    }

    /**
     * Get public settings for a specific group.
     *
     * A group that exposes no public settings is reported as 404 rather than as an
     * empty 200, so callers can tell "no such group" from "group with nothing in it".
     */
    public function show(string $group): JsonResponse
    {
        try {
            return $this->successResponse($this->settingService->getPublicGroup($group));
        } catch (SettingGroupNotFoundException $e) {
            return $this->errorResponse('SETTING_GROUP_NOT_FOUND', $e->getMessage(), null, 404);
        }
    }
}
