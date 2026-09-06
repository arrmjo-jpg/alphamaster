<?php

declare(strict_types=1);

namespace App\Modules\Settings\Controllers\Admin;

use App\Modules\Core\Controllers\BaseApiController;
use App\Modules\Settings\Contracts\SettingServiceInterface;
use App\Modules\Settings\Definitions\SettingRegistry;
use App\Modules\Settings\Exceptions\SettingGroupNotFoundException;
use App\Modules\Settings\Exceptions\UnknownSettingKeyException;
use App\Modules\Settings\Requests\UpdateGroupSettingsRequest;
use App\Modules\Settings\Resources\SettingDefinitionResource;
use Illuminate\Contracts\Auth\Access\Authorizable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

class SettingAdminController extends BaseApiController
{
    public function __construct(
        protected SettingServiceInterface $settingService,
        protected SettingRegistry $registry,
    ) {}

    /**
     * The catalogue: what settings exist and what the rules are for each.
     *
     * The question the registry was built to answer, and the one a future Admin UI
     * needs before it can render a form for anything. It carries no values — a
     * definition describes a setting, and what one is set to is a different endpoint
     * with a different shape.
     *
     * Deprecated definitions are included and flagged rather than hidden, so an
     * interface can show an operator that a setting they configured is on its way out.
     */
    public function definitions(): JsonResponse
    {
        $grouped = [];

        foreach ($this->registry->all() as $definition) {
            $grouped[$definition->group][] = (new SettingDefinitionResource($definition))->resolve();
        }

        return $this->successResponse($grouped);
    }

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

            $forbidden = $this->assertKeyPermissions($request, $group, $payload);

            if ($forbidden !== null) {
                return $forbidden;
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
     * Refuse a write touching a key the caller is not entitled to change.
     *
     * Checked per key rather than per route, because sensitivity is a property of the
     * setting and not of the group it lives in: `settings.update` is enough to rename
     * the site, and is deliberately not enough to widen the login throttle, replace a
     * credential, or shorten how long the record of who did what survives.
     *
     * A secret needs `settings.secrets.manage` whatever group it is in, so a
     * credential added to any future catalogue is covered the moment it is declared
     * rather than when somebody remembers to guard it.
     *
     * @param  array<string, mixed>  $payload
     */
    private function assertKeyPermissions(Request $request, string $group, array $payload): ?JsonResponse
    {
        $user = $request->user();

        if (! $user instanceof Authorizable) {
            return null;
        }

        foreach (array_keys($payload) as $key) {
            $reference = $group.'.'.(string) $key;

            if (! $this->registry->has($reference)) {
                continue;
            }

            $required = $this->registry->get($reference)->requiredPermission();

            // Asked through the framework's own authorization API rather than the
            // Authorization module's contract: Settings depends on Core and the
            // framework only (ADR 0002), and Spatie registers permissions with the
            // Gate, so `can()` is the same answer by a permitted route.
            if ($required === null || $user->can($required)) {
                continue;
            }

            return $this->errorResponse(
                'PERMISSION_DENIED',
                'api.error.settings.permission_required',
                ['setting' => $reference, 'permission' => $required],
                403,
            );
        }

        return null;
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
