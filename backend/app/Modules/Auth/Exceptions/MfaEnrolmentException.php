<?php

declare(strict_types=1);

namespace App\Modules\Auth\Exceptions;

use RuntimeException;

/**
 * Raised when an MFA enrolment or management action is not valid for the user's
 * current state (no pending enrolment, already confirmed, already enabled).
 */
class MfaEnrolmentException extends RuntimeException {}
