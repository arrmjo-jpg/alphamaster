<?php

declare(strict_types=1);

namespace App\Modules\Notification\Services;

use App\Modules\Notification\Contracts\NotifierContract;
use App\Modules\Notification\Enums\NotificationType;
use App\Modules\Notification\Notifications\TemplatedNotification;
use App\Modules\User\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Notification as LaravelNotification;

/**
 * Raises notifications.
 *
 * Deliberately thin: which channels apply is the recipient's preference, and what the
 * message says is the template's. This exists so consumers depend on one small
 * contract rather than on Laravel's notification facade plus the type registry plus
 * the preference rules.
 */
class Notifier implements NotifierContract
{
    /**
     * @param  array<string, string|int>  $placeholders
     */
    public function send(User $recipient, NotificationType $type, array $placeholders = []): void
    {
        LaravelNotification::send([$recipient], new TemplatedNotification($type, $placeholders));
    }

    /**
     * @param  Collection<int, User>|array<int, User>  $recipients
     * @param  array<string, string|int>  $placeholders
     */
    public function sendMany(Collection|array $recipients, NotificationType $type, array $placeholders = []): void
    {
        LaravelNotification::send($recipients, new TemplatedNotification($type, $placeholders));
    }
}
