<?php

declare(strict_types=1);

namespace App\Modules\Settings\Exceptions;

use RuntimeException;

/**
 * A rollback named a revision that does not belong to the group being rolled back
 * (ADR 0040).
 *
 * Covers both a revision that does not exist and one that exists but describes a
 * setting in some other group. The two answer identically on purpose: distinguishing
 * them would let a caller with `settings.rollback` on one group enumerate which
 * revision identifiers exist elsewhere.
 */
class UnknownRevisionException extends RuntimeException
{
    public function __construct(private readonly string $group, private readonly string $revisionId)
    {
        parent::__construct("Revision [{$revisionId}] does not belong to settings group [{$group}].");
    }

    public function translationKey(): string
    {
        return 'api.error.settings.revision_not_found';
    }

    /**
     * @return array<string, string>
     */
    public function translationParameters(): array
    {
        return ['group' => $this->group, 'revision' => $this->revisionId];
    }
}
