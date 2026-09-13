<?php

declare(strict_types=1);

namespace App\Modules\User\Contracts;

use App\Modules\User\Exceptions\PromotionRefusedException;
use App\Modules\User\Models\User;

interface AccountTypeManagerContract
{
    /**
     * Promote an account to administrator, revoking its existing tokens.
     *
     * Refused, with nothing changed, while any social identity is linked to the account
     * (ADR 0050 §6). An account that is already an administrator is returned unchanged.
     *
     * @throws PromotionRefusedException
     */
    public function promote(User $user): User;

    /**
     * Demote an administrator, revoking its tokens and stripping admin RBAC relations.
     */
    public function demote(User $user): User;
}
