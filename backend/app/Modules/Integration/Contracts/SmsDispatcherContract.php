<?php

declare(strict_types=1);

namespace App\Modules\Integration\Contracts;

use App\Modules\Integration\Data\SmsMessage;
use App\Modules\Integration\Data\SmsResult;
use App\Modules\Integration\Exceptions\NoProviderConfiguredException;

/**
 * The entry point every consumer uses. Selects a provider, falls back on failure,
 * and records each attempt.
 */
interface SmsDispatcherContract
{
    /**
     * @throws NoProviderConfiguredException when no active provider exists
     */
    public function send(SmsMessage $message): SmsResult;
}
