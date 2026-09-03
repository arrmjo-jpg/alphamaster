<?php

declare(strict_types=1);

namespace App\Modules\Auth\Exceptions;

use RuntimeException;

/**
 * Raised when a code cannot be delivered right now — most often because one was sent
 * moments ago and the cooldown has not elapsed.
 */
class MfaDeliveryException extends RuntimeException {}
