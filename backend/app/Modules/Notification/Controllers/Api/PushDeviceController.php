<?php

declare(strict_types=1);

namespace App\Modules\Notification\Controllers\Api;

use App\Modules\Core\Controllers\BaseApiController;
use App\Modules\Notification\Models\PushDevice;
use App\Modules\Notification\Requests\RegisterPushDeviceRequest;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * The devices an account can be reached on.
 *
 * Behind no permission, for the reason phone verification is: every route here acts on
 * `$request->user()` and none takes an account identifier, so there is no way to
 * register a device against somebody else's account however they are called.
 *
 * The shape that matters is the *replace* in registration. FCM rotates tokens, so a
 * client that registered on Tuesday holds a different token on Friday for the same
 * handset — and without a stable handle for the device, Friday's registration would
 * leave Tuesday's row behind and the phone would receive everything twice. The client
 * generates a `device_id`, keeps it, and sends it every time; the platform replaces the
 * token rather than adding a row (ADR 0045 §5).
 */
class PushDeviceController extends BaseApiController
{
    /**
     * The caller's own devices.
     *
     * The registration token is never returned — it is a delivery address for a
     * specific handset, and anybody holding it could send to that device directly.
     * A hint of the last few characters is enough to tell two rows apart.
     */
    #[Response(200, type: 'array{success: bool, data: array<int, array{id: string, device_id: string, platform: string, platform_label: string, label: string|null, token_hint: string, last_seen_at: string|null, registered_at: string|null}>}')]
    public function index(Request $request): JsonResponse
    {
        $devices = PushDevice::query()
            ->forAccount((string) $request->user()?->getAuthIdentifier())
            ->orderByDesc('last_seen_at')
            ->orderByDesc('created_at')
            ->get();

        return $this->successResponse(
            $devices->map(fn (PushDevice $device): array => $this->present($device))->all()
        );
    }

    /**
     * Register this device, or replace the token it registered with last time.
     */
    #[Response(200, type: 'array{success: bool, message: string, data: array{id: string, device_id: string, platform: string, platform_label: string, label: string|null, token_hint: string, last_seen_at: string|null, registered_at: string|null}}')]
    public function store(RegisterPushDeviceRequest $request): JsonResponse
    {
        $userId = (string) $request->user()?->getAuthIdentifier();
        $deviceId = (string) $request->validated('device_id');

        $device = DB::transaction(function () use ($request, $userId, $deviceId): PushDevice {
            /** @var PushDevice $device */
            $device = PushDevice::query()->updateOrCreate(
                ['user_id' => $userId, 'device_id' => $deviceId],
                [
                    'token' => (string) $request->validated('token'),
                    'platform' => (string) $request->validated('platform'),
                    'label' => $request->validated('label'),
                    // The session this registration belongs to, so signing out of it
                    // stops delivery to this handset and leaves other sessions alone.
                    'access_token_id' => $this->accessTokenId($request),
                    'last_seen_at' => now(),
                ]
            );

            return $device;
        });

        return $this->successResponse(
            $this->present($device),
            'api.notifications.device_registered'
        );
    }

    /**
     * Stop delivering to one of the caller's devices.
     *
     * Scoped to the caller's own rows in the query rather than checked afterwards:
     * that is the whole of the authorization here, so it belongs where it cannot be
     * forgotten.
     */
    #[Response(200, type: 'array{success: bool, message: string, data: array{id: string}}')]
    #[Response(404, description: 'The caller has no device with that identifier.')]
    public function destroy(Request $request, string $device): JsonResponse
    {
        $row = PushDevice::query()
            ->forAccount((string) $request->user()?->getAuthIdentifier())
            ->where('id', $device)
            ->first();

        if ($row === null) {
            return $this->errorResponse(
                'DEVICE_NOT_FOUND',
                'api.error.notifications.device_not_found',
                null,
                404
            );
        }

        $row->delete();

        return $this->successResponse(['id' => $device], 'api.notifications.device_forgotten');
    }

    /**
     * The id of the token this request arrived with, where there is one.
     *
     * A cookie-borne session resolves to a real `PersonalAccessToken` (ADR 0042), so
     * this works for a browser and for a mobile client holding a bearer token alike.
     */
    private function accessTokenId(Request $request): ?string
    {
        $token = $request->user()?->currentAccessToken();

        return $token instanceof PersonalAccessToken ? (string) $token->getKey() : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function present(PushDevice $device): array
    {
        return [
            'id' => $device->id,
            'device_id' => $device->device_id,
            'platform' => $device->platform->value,
            'platform_label' => $device->platform->label(),
            'label' => $device->label,
            'token_hint' => $device->tokenHint(),
            'last_seen_at' => $device->last_seen_at?->toIso8601String(),
            'registered_at' => $device->created_at?->toIso8601String(),
        ];
    }
}
