<?php

declare(strict_types=1);

namespace App\Modules\Notification\Channels;

use App\Modules\Core\Contracts\SmsRecipientResolverInterface;
use App\Modules\Integration\Contracts\SmsDispatcherContract;
use App\Modules\Integration\Data\SmsMessage;
use App\Modules\Integration\Exceptions\NoProviderConfiguredException;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;

/**
 * Delivers a notification by SMS through the Integration module.
 *
 * The channel owns no transport: provider selection, failover and usage logging all
 * belong to Integration (ADR 0017), so changing vendor is invisible here.
 */
class SmsChannel
{
    public function __construct(
        private readonly SmsDispatcherContract $sms,
        private readonly SmsRecipientResolverInterface $recipients,
    ) {}

    /**
     * Send the notification.
     *
     * A recipient with no reachable number is skipped rather than treated as a
     * failure: not everyone has given the platform a phone number, and a notification
     * that could not take one route should not fail the ones it could.
     */
    public function send(mixed $notifiable, Notification $notification): void
    {
        if (! method_exists($notification, 'toSms')) {
            return;
        }

        $destination = $this->destinationFor($notifiable);

        if ($destination === null) {
            return;
        }

        try {
            $this->sms->send(new SmsMessage($destination, (string) $notification->toSms($notifiable)));
        } catch (NoProviderConfiguredException $e) {
            // Nothing is configured to send with. That is an operator problem, not a
            // reason to fail the whole notification, and Integration has already
            // recorded nothing was sent.
            Log::warning('An SMS notification could not be delivered.', [
                'reason' => $e->getMessage(),
                'notification' => $notification::class,
            ]);
        }
    }

    /**
     * Where to send, if the recipient has a number.
     *
     * Resolved through the Core contract, so this channel does not need to know which
     * module owns the number or how it was confirmed. Never logged or returned.
     */
    private function destinationFor(mixed $notifiable): ?string
    {
        return is_object($notifiable) ? $this->recipients->resolve($notifiable) : null;
    }
}
