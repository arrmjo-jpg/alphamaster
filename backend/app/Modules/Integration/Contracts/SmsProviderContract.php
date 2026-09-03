<?php

declare(strict_types=1);

namespace App\Modules\Integration\Contracts;

use App\Modules\Integration\Data\SmsMessage;
use App\Modules\Integration\Data\SmsResult;
use App\Modules\Integration\Models\IntegrationProvider;

/**
 * One SMS vendor.
 *
 * Implementations receive their configuration from the database rather than from
 * config files, which is what lets a vendor be swapped from the Admin UI without a
 * deploy (ADR 0017).
 */
interface SmsProviderContract
{
    /**
     * The driver name this implementation answers to.
     */
    public function driver(): string;

    /**
     * Attempt a send. Returns a failure result rather than throwing for a vendor
     * rejection; exceptions are reserved for programming and configuration faults.
     */
    public function send(SmsMessage $message, IntegrationProvider $provider): SmsResult;
}
