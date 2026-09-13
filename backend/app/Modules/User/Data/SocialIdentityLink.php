<?php

declare(strict_types=1);

namespace App\Modules\User\Data;

use App\Modules\User\Models\SocialIdentity;

/**
 * The outcome of a link: the identity, and what the link actually did.
 *
 * `relinked` is a subject that was unlinked from this account and is linked again on the
 * same row. `alreadyLinked` is a link that changed nothing, which records nothing.
 */
final readonly class SocialIdentityLink
{
    public function __construct(
        public SocialIdentity $identity,
        public bool $relinked,
        public bool $alreadyLinked,
    ) {}
}
