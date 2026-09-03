<?php

declare(strict_types=1);

namespace App\Modules\Auth\Exceptions;

use RuntimeException;

/**
 * Raised when an MFA challenge token is missing, expired, or the submitted code
 * does not verify.
 */
class MfaChallengeException extends RuntimeException {}
