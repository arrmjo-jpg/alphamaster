<?php

declare(strict_types=1);

namespace App\Modules\Notification\Controllers\Api;

use App\Modules\Core\Controllers\BaseApiController;
use App\Modules\Notification\Contracts\PreferenceResolverContract;
use App\Modules\Notification\Enums\NotificationChannel;
use App\Modules\Notification\Enums\NotificationType;
use App\Modules\Notification\Requests\UpdateNotificationPreferencesRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * A recipient's own notification preferences. Not administrative: these are the
 * caller's settings about their own messages.
 */
class NotificationPreferenceController extends BaseApiController
{
    public function __construct(
        protected PreferenceResolverContract $preferences
    ) {}

    /**
     * Every effective decision, defaults included, so a client can render the whole
     * matrix without inferring which combinations exist.
     */
    public function index(Request $request): JsonResponse
    {
        return $this->successResponse($this->preferences->describe($request->user()));
    }

    /**
     * Update preferences.
     *
     * A combination the recipient may not silence is refused rather than quietly
     * ignored, so a client is told its request had no effect instead of showing a
     * switch that appears to have moved.
     */
    public function update(UpdateNotificationPreferencesRequest $request): JsonResponse
    {
        $user = $request->user();

        /** @var array<int, array<string, mixed>> $submitted */
        $submitted = $request->validated('preferences');

        try {
            DB::transaction(function () use ($user, $submitted): void {
                foreach ($submitted as $preference) {
                    $this->preferences->set(
                        $user,
                        NotificationType::from((string) $preference['type']),
                        NotificationChannel::from((string) $preference['channel']),
                        (bool) $preference['enabled'],
                    );
                }
            });
        } catch (\InvalidArgumentException $e) {
            return $this->errorResponse('PREFERENCE_NOT_SILENCEABLE', $e->getMessage(), null, 422);
        }

        return $this->successResponse($this->preferences->describe($user), 'Preferences updated.');
    }
}
