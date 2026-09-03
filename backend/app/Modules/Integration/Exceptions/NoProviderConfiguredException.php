<?php

declare(strict_types=1);

namespace App\Modules\Integration\Exceptions;

use App\Modules\Integration\Enums\IntegrationCapability;
use RuntimeException;

/**
 * Raised when a capability is requested but no active provider is configured for it.
 */
class NoProviderConfiguredException extends RuntimeException
{
    public function __construct(public readonly IntegrationCapability $capability)
    {
        parent::__construct(
            "No active provider is configured for the [{$capability->value}] capability."
        );
    }
}
