<?php

declare(strict_types=1);

namespace App\Modules\Integration\Exceptions;

use RuntimeException;

/**
 * A social provider exchange or token validation that did not succeed.
 *
 * The message is written by the platform and never carries a vendor's response body, an
 * authorization code, a token, a verifier or a credential: it is recorded in the usage
 * log, and the log is readable by anyone who may view integrations. The code says which
 * check failed, so an operator can tell a misconfigured client from a forged token.
 */
class SocialProviderException extends RuntimeException
{
    public function __construct(public readonly string $errorCode, string $message)
    {
        parent::__construct($message);
    }
}
