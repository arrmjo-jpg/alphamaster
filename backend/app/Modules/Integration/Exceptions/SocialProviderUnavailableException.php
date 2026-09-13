<?php

declare(strict_types=1);

namespace App\Modules\Integration\Exceptions;

use RuntimeException;

/**
 * Raised when a social provider is unknown, inactive or not effective (ADR 0050 §10).
 *
 * Technical rather than localized: the consumer answers every one of these the same way,
 * so a caller cannot tell an unknown provider from a disabled or half-configured one.
 */
class SocialProviderUnavailableException extends RuntimeException
{
    public function __construct(public readonly string $provider)
    {
        parent::__construct("The social login provider [{$provider}] is not available.");
    }
}
