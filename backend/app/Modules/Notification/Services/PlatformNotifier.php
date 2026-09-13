<?php

declare(strict_types=1);

namespace App\Modules\Notification\Services;

use App\Modules\Core\Contracts\PlatformNotifierContract;
use App\Modules\Notification\Contracts\NotifierContract;
use App\Modules\Notification\Enums\NotificationType;
use App\Modules\User\Models\User;
use InvalidArgumentException;
use Throwable;

/**
 * The Core seam's implementation: turns a producer's string into this module's type.
 *
 * Everything it does beyond translating the identifier is refusal. A type that names
 * nothing, or a recipient that is not an account, is a programming error in the
 * producer — and a test reads every producer's literal so neither should reach here.
 * If one does, it is reported and dropped rather than thrown, because the producer's
 * own operation has already happened and must not be reported to its caller as failed.
 */
class PlatformNotifier implements PlatformNotifierContract
{
    public function __construct(private readonly NotifierContract $notifier) {}

    public function notify(object $recipient, string $type, array $placeholders = []): void
    {
        $resolved = NotificationType::tryFrom($type);

        if ($resolved === null) {
            report(new InvalidArgumentException("[{$type}] is not a notification type."));

            return;
        }

        if (! $recipient instanceof User) {
            report(new InvalidArgumentException(
                sprintf('A [%s] notification was raised for a %s, which is not an account.', $type, $recipient::class)
            ));

            return;
        }

        // Every seeded template greets its reader by name, and the producer that knows
        // the occasion should not also have to know that. A producer that supplies one
        // wins.
        $placeholders += ['name' => (string) $recipient->name];

        // Queued in any real deployment, so this is only the dispatch — but on a sync
        // queue the whole delivery runs here, and a missing template or an unreachable
        // mailer would otherwise surface as a 500 on an edit that already committed.
        // The contract says a notification that cannot be raised never fails its
        // producer, and that has to hold whichever queue is configured.
        try {
            $this->notifier->send($recipient, $resolved, $placeholders);
        } catch (Throwable $e) {
            report($e);
        }
    }
}
