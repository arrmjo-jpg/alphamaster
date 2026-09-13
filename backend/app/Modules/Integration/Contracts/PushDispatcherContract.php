<?php

declare(strict_types=1);

namespace App\Modules\Integration\Contracts;

use App\Modules\Integration\Data\PushMessage;
use App\Modules\Integration\Data\PushResult;

/**
 * Sending a push, without the caller knowing who carries it.
 *
 * The seam the Notification module's push channel depends on, exactly as it depends on
 * `SmsDispatcherContract` for text messages: provider selection, failover and usage
 * recording are part of sending, not the channel's job (ADR 0017, ADR 0045 §2).
 */
interface PushDispatcherContract
{
    public function send(PushMessage $message): PushResult;

    /**
     * Whether a push would reach a vendor at all.
     *
     * Read by the channel so a platform with no push provider skips the route instead
     * of logging a failure per recipient.
     */
    public function isConfigured(): bool;
}
