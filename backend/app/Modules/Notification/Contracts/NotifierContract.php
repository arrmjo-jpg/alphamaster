<?php

declare(strict_types=1);

namespace App\Modules\Notification\Contracts;

use App\Modules\Notification\Enums\NotificationType;
use App\Modules\User\Models\User;
use Illuminate\Support\Collection;

/**
 * The entry point every consumer uses to raise a notification.
 */
interface NotifierContract
{
    /**
     * Queue a notification for one recipient.
     *
     * @param  array<string, string|int>  $placeholders
     */
    public function send(User $recipient, NotificationType $type, array $placeholders = []): void;

    /**
     * Queue a notification for many recipients.
     *
     * @param  Collection<int, User>|array<int, User>  $recipients
     * @param  array<string, string|int>  $placeholders
     */
    public function sendMany(Collection|array $recipients, NotificationType $type, array $placeholders = []): void;
}
