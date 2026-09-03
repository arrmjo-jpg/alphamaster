<?php

declare(strict_types=1);

namespace App\Modules\Core\Contracts;

/**
 * Resolves where a notifiable can be reached by SMS.
 *
 * Defined in Core so the module that knows the number and the module that sends the
 * message need not know about each other. Auth owns the confirmed number; Notification
 * needs it; neither depends on the other, which keeps Auth's existing dependency on
 * User from becoming a cycle.
 */
interface SmsRecipientResolverInterface
{
    /**
     * The number to send to, or null when the recipient has none.
     */
    public function resolve(object $notifiable): ?string;
}
