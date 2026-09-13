<?php

declare(strict_types=1);

namespace App\Modules\Auth\Data;

use App\Modules\User\Models\User;

/**
 * Who a phone code signed in, and whether answering it created the account.
 */
final readonly class PhoneSignInResult
{
    public function __construct(
        public User $user,
        public bool $created,
    ) {}
}
