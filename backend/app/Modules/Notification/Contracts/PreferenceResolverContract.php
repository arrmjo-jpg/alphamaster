<?php

declare(strict_types=1);

namespace App\Modules\Notification\Contracts;

use App\Modules\Notification\Enums\NotificationChannel;
use App\Modules\Notification\Enums\NotificationType;
use App\Modules\User\Models\User;

interface PreferenceResolverContract
{
    /**
     * The channels this notification should be delivered on for this recipient.
     *
     * @return array<int, NotificationChannel>
     */
    public function channelsFor(User $user, NotificationType $type): array;

    /**
     * Record a recipient's choice.
     */
    public function set(User $user, NotificationType $type, NotificationChannel $channel, bool $enabled): void;

    /**
     * Every effective decision for a recipient, defaults included.
     *
     * @return array<int, array<string, mixed>>
     */
    public function describe(User $user): array;

    /**
     * Whether a recipient may turn this combination off at all.
     */
    public function isSilenceable(NotificationType $type, NotificationChannel $channel): bool;
}
