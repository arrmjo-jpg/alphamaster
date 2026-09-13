<?php

declare(strict_types=1);

namespace App\Modules\User\Contracts;

use App\Modules\User\Data\SocialIdentityLink;
use App\Modules\User\Exceptions\SocialIdentityException;
use App\Modules\User\Models\SocialIdentity;
use App\Modules\User\Models\User;
use Illuminate\Support\Collection;

/**
 * The social identities an account holds, and the only way they change (ADR 0050 §5).
 *
 * Declared here, in the module that owns the table, and consumed by Auth, which owns the
 * sign-in flow. Every change is a transaction that locks the account first, so a link and
 * a promotion of the same account cannot interleave.
 */
interface SocialIdentityRegistryContract
{
    /**
     * The identity for a provider subject, linked or not.
     */
    public function findBySubject(string $provider, string $subject): ?SocialIdentity;

    /**
     * Whether any identity is linked to the account today.
     */
    public function hasLinkedIdentity(User $user): bool;

    /**
     * The account's linked identities, in provider order.
     *
     * @return Collection<int, SocialIdentity>
     */
    public function linkedIdentities(User $user): Collection;

    /**
     * Link an identity to an account, or re-link one the account held before.
     *
     * No email is matched against anything here: the caller has already established that
     * this person signed in as this account.
     *
     * @throws SocialIdentityException
     */
    public function link(User $user, string $provider, string $subject, ?string $email): SocialIdentityLink;

    /**
     * Unlink one of the account's linked identities, keeping the row.
     *
     * @throws SocialIdentityException
     */
    public function unlink(User $user, string $identityId): SocialIdentity;

    /**
     * Note that an identity was just used to sign in.
     */
    public function recordUse(SocialIdentity $identity): void;
}
