<?php

declare(strict_types=1);

namespace App\Modules\Auth\Services;

use App\Modules\Auth\Models\MfaMethod;
use App\Modules\Core\Contracts\SmsRecipientResolverInterface;
use App\Modules\User\Models\User;

/**
 * Resolves an SMS destination from the number confirmed during MFA enrolment.
 *
 * Reuses the one number the account has actually proved it holds rather than
 * introducing a second phone field, which would inevitably drift out of step with it.
 */
class ConfirmedMfaSmsRecipientResolver implements SmsRecipientResolverInterface
{
    public function resolve(object $notifiable): ?string
    {
        if (! $notifiable instanceof User) {
            return null;
        }

        $method = MfaMethod::query()
            ->where('user_id', $notifiable->id)
            ->confirmed()
            ->whereNotNull('destination')
            ->first();

        return $method?->getDestination();
    }
}
