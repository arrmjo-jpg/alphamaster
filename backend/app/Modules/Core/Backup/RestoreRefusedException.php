<?php

declare(strict_types=1);

namespace App\Modules\Core\Backup;

use RuntimeException;

/**
 * A configuration export could not be read, or describes a shape this release does not
 * understand (ADR 0039).
 *
 * Distinct from a restore that ran and skipped things. This one wrote nothing at all,
 * because the document could not be trusted enough to start — and a restore that begins
 * on a document it cannot read leaves a deployment half-configured from a file nobody
 * can inspect.
 */
class RestoreRefusedException extends RuntimeException
{
    public function __construct(private readonly string $reason)
    {
        parent::__construct("The configuration export was refused: {$reason}.");
    }

    public function translationKey(): string
    {
        return 'api.error.'.$this->reason;
    }
}
