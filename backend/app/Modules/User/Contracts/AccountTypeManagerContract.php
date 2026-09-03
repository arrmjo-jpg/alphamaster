<?php

declare(strict_types=1);

namespace App\Modules\User\Contracts;

use App\Modules\User\Models\User;

interface AccountTypeManagerContract
{
    /**
     * Promote an account to administrator, revoking its existing tokens.
     */
    public function promote(User $user): User;

    /**
     * Demote an administrator, revoking its tokens and stripping admin RBAC relations.
     */
    public function demote(User $user): User;
}
