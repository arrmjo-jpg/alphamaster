<?php

declare(strict_types=1);

namespace App\Modules\Settings\Controllers\Admin;

use App\Modules\Core\Controllers\BaseApiController;
use App\Modules\Settings\Concerns\AssertsSettingPrecondition;
use App\Modules\Settings\Contracts\SettingServiceInterface;
use App\Modules\Settings\Exceptions\UnknownSettingKeyException;
use App\Modules\Settings\Requests\RotateSecretRequest;
use App\Modules\Settings\Services\SecretRotationService;
use Illuminate\Http\JsonResponse;

/**
 * Rotating credentials (ADR 0038, as extended).
 *
 * Its own controller rather than another action on the settings one, because every
 * route here handles material that must not appear in a response, a log or a record.
 * Keeping them together means the rules about what may be echoed apply to a file
 * rather than to individual methods scattered among endpoints that have no such
 * constraint.
 */
class SecretAdminController extends BaseApiController
{
    use AssertsSettingPrecondition;

    public function __construct(
        protected SecretRotationService $rotation,
        protected SettingServiceInterface $settingService,
    ) {}

    /**
     * Replace one stored credential.
     *
     * Verified with the vendor first where a verifier exists, and committed only then.
     * Where none exists the response says `unavailable` rather than `verified`, because
     * an operator reading "rotated" needs to know whether anything confirmed it.
     *
     * The response carries the outcome and never the credential — not the new one, not
     * the old one, not a masked or truncated form of either.
     */
    public function rotate(RotateSecretRequest $request, string $group, string $key): JsonResponse
    {
        /** @var string $candidate */
        $candidate = $request->validated()['credential'];

        $precondition = $this->assertPrecondition($request, $group);

        if ($precondition !== null) {
            return $precondition;
        }

        try {
            $result = $this->rotation->rotate($group, $key, $candidate);
        } catch (UnknownSettingKeyException $e) {
            return $this->errorResponse('SETTING_KEY_NOT_FOUND', $e->translationKey(), null, 404, $e->translationParameters());
        }

        if (! $result->permitsCommit()) {
            // 422, and nothing was written. The stored credential is exactly what it
            // was: not cleared, not replaced, not partially applied (ADR 0038).
            return $this->errorResponse(
                'SECRET_VERIFICATION_FAILED',
                'api.error.settings.secret_verification_failed',
                $result->toArray(),
                422,
            );
        }

        $version = $this->settingService->groupVersion($group);

        return $this->successResponse(
            data: ['key' => $group.'.'.$key, 'verification' => $result->toArray()],
            message: 'api.settings.secret_rotated',
            replace: ['key' => $group.'.'.$key],
            meta: ['version' => $version],
        )->header('ETag', '"'.$version.'"');
    }
}
