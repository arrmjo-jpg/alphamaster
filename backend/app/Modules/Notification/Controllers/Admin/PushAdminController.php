<?php

declare(strict_types=1);

namespace App\Modules\Notification\Controllers\Admin;

use App\Modules\Core\Controllers\BaseApiController;
use App\Modules\Integration\Services\CapabilityStatus;
use App\Modules\Integration\Services\PushManager;
use App\Modules\Notification\Models\PushDevice;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * What an operator can see about push: whether it is configured, and who it reaches.
 *
 * Read-only, deliberately. An administrator has no business registering a device on
 * somebody's behalf — a registration is a claim that a particular handset belongs to a
 * particular account, and only the account holder's own client can make it (ADR 0045
 * §5). Removing one is a different matter and is offered, because a device that should
 * no longer receive is an operational problem an operator has to be able to solve.
 *
 * The registration token is never returned. It is a delivery address for one handset,
 * and anybody holding it could send to that device directly; a hint of the last few
 * characters is enough to tell two rows apart in a list.
 */
class PushAdminController extends BaseApiController
{
    public function __construct(
        private readonly CapabilityStatus $status,
        private readonly PushManager $manager,
    ) {}

    /**
     * The registry, and whether anything could be delivered through it.
     *
     * Both in one response because the two are read together: a registry of forty
     * devices means nothing if no provider is configured, and a configured provider
     * means nothing if nobody has registered.
     */
    #[Response(200, type: 'array{success: bool, data: array{configured: bool, provider: array{driver: string, label: string, has_credentials: bool}|null, available_drivers: array<int, string>, last_attempt: array{status: string, at: string, error_code: string|null, error_message: string|null, duration_ms: int|null, units: int|null}|null, recent_failures: int, devices: array<int, array{id: string, user_id: string, platform: string, platform_label: string, label: string|null, token_hint: string, last_seen_at: string|null, registered_at: string|null, stale: bool}>, total: int, stale: int}}')]
    public function index(Request $request): JsonResponse
    {
        $devices = PushDevice::query()
            ->orderByDesc('last_seen_at')
            ->orderByDesc('created_at')
            ->limit(200)
            ->get();

        // The capability's own status — vendor selected, credential held, last answer —
        // beside the registry, because the two are read together: forty devices mean
        // nothing if no provider is configured, and a configured provider means nothing
        // if nobody has registered.
        return $this->successResponse(array_merge($this->status->describe($this->manager), [
            'devices' => $devices->map(fn (PushDevice $device): array => $this->present($device))->all(),
            'total' => PushDevice::query()->count(),
            // A device nothing has reached for a long time is the registry's version of
            // rot: the app was removed, the token rotated without the client
            // re-registering, or the handset is simply gone. Counted rather than
            // deleted — pruning is the retention policy's decision, not a side effect
            // of somebody opening a screen.
            'stale' => $this->staleQuery()->count(),
        ]));
    }

    /**
     * Remove one device from the registry.
     *
     * Not a way to stop somebody being notified — that is what preferences are for, and
     * a client that is still installed will re-register on its next run. It is for the
     * rows that should not be there: a handset that was handed on, a token nothing has
     * reached in months.
     */
    #[Response(200, type: 'array{success: bool, message: string, data: array{id: string}}')]
    #[Response(404, description: 'No device with that identifier.')]
    public function destroy(string $device): JsonResponse
    {
        $row = PushDevice::query()->find($device);

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
     * Devices nothing has reached for thirty days, or ever.
     *
     * Thirty days is the point at which "quiet" stops being plausible for a device
     * that is still installed: the platform sends security alerts and account updates,
     * and a month of silence to one handset while others answered is a dead address.
     *
     * @return Builder<PushDevice>
     */
    private function staleQuery()
    {
        $cutoff = now()->subDays(30);

        return PushDevice::query()
            ->where(fn ($query) => $query
                ->whereNull('last_seen_at')
                ->orWhere('last_seen_at', '<', $cutoff));
    }

    /**
     * @return array<string, mixed>
     */
    private function present(PushDevice $device): array
    {
        $stale = $device->last_seen_at === null || $device->last_seen_at->lt(now()->subDays(30));

        return [
            'id' => $device->id,
            // Which account, so an operator can act on a report. Not the account's name
            // or address: this endpoint is about the registry, and a caller who needs to
            // know who that is has the accounts screen.
            'user_id' => $device->user_id,
            'platform' => $device->platform->value,
            'platform_label' => $device->platform->label(),
            'label' => $device->label,
            'token_hint' => $device->tokenHint(),
            'last_seen_at' => $device->last_seen_at?->toIso8601String(),
            'registered_at' => $device->created_at?->toIso8601String(),
            'stale' => $stale,
        ];
    }
}
