<?php

declare(strict_types=1);

namespace App\Modules\Notification\Channels;

use App\Modules\Integration\Contracts\PushDispatcherContract;
use App\Modules\Integration\Data\PushMessage;
use App\Modules\Notification\Models\PushDevice;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;

/**
 * Delivers a notification to a recipient's registered devices.
 *
 * The channel owns no transport: provider selection, failover and usage logging belong
 * to Integration (ADR 0017), so replacing Firebase is invisible here.
 *
 * What it does own is the registry. Three rules, and each exists because the
 * alternative is a table of addresses nobody reads (ADR 0045 §5):
 *
 *   * a recipient with no registered device is skipped, not failed — the same rule the
 *     SMS channel follows for somebody with no number;
 *   * a vendor saying a token no longer exists is authoritative, so the row goes;
 *   * everything else is a transient failure, logged and left alone.
 *
 * The payload carries a type and a record id and no content at all. That is the
 * decision the whole feature is shaped around, and it lives in `PushMessage` so no
 * channel can widen it by accident.
 */
class PushChannel
{
    public function __construct(private readonly PushDispatcherContract $push) {}

    public function send(mixed $notifiable, Notification $notification): void
    {
        if (! method_exists($notification, 'toPush')) {
            return;
        }

        if (! $this->push->isConfigured()) {
            // No vendor. Skipped silently rather than logged per recipient: an
            // announcement to a thousand accounts must not write a thousand identical
            // lines saying the operator has not configured Firebase.
            return;
        }

        $recipientId = $this->recipientId($notifiable);

        if ($recipientId === null) {
            return;
        }

        /** @var string $type */
        $type = (string) $notification->toPush($notifiable);

        // The in-app record's id, assigned by the framework before any channel runs, so
        // a device that fetches it finds the same message the inbox shows. If the
        // database channel has not written the row yet, the device retries — which is
        // one more reason the payload carries an id rather than a body.
        $recordId = $notification->id;

        if ($recordId === '') {
            return;
        }

        foreach (PushDevice::query()->forAccount($recipientId)->get() as $device) {
            $this->deliver($device, $type, $recordId);
        }
    }

    private function deliver(PushDevice $device, string $type, string $recordId): void
    {
        $result = $this->push->send(new PushMessage($device->token, $type, $recordId));

        if ($result->successful) {
            // Evidence the address still works, and what the retention policy prunes on.
            $device->forceFill(['last_seen_at' => now()])->save();

            return;
        }

        if ($result->tokenInvalid) {
            // The vendor says this address is dead. Keeping it means retrying forever,
            // and a registry that only grows is one an operator stops trusting.
            $device->delete();

            return;
        }

        // Everything else is the vendor being unavailable rather than the device being
        // gone. Integration has already recorded the attempt; this line says which
        // device it was about, which the usage log deliberately does not.
        Log::warning('A push notification could not be delivered.', [
            'device' => $device->id,
            'error_code' => $result->errorCode,
        ]);
    }

    /**
     * Which account to look up devices for.
     *
     * Read from the notifiable's own key rather than through a contract, because a
     * device row is this module's and is keyed by exactly the id Laravel already used
     * to address the notification.
     */
    private function recipientId(mixed $notifiable): ?string
    {
        if (! is_object($notifiable) || ! method_exists($notifiable, 'getKey')) {
            return null;
        }

        $key = $notifiable->getKey();

        return is_string($key) && $key !== '' ? $key : null;
    }
}
