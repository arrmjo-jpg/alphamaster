<?php

declare(strict_types=1);

namespace App\Modules\Auth\Data;

use App\Modules\User\Models\User;

/**
 * Who a social callback signed in, and whether it created them.
 *
 * Always a user account: the flow refuses an administrator before it can produce one.
 */
final readonly class SocialSignIn
{
    public function __construct(
        public User $user,
        public bool $created,
    ) {}
}
