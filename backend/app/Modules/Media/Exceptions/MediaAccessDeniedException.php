<?php

declare(strict_types=1);

namespace App\Modules\Media\Exceptions;

use RuntimeException;

/**
 * Raised when a viewer may not reach a private file.
 *
 * Deliberately identical whether the file is absent or merely forbidden, so the API
 * cannot be used to discover which media ids exist.
 */
class MediaAccessDeniedException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('This media is not available.');
    }
}
