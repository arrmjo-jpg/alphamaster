<?php

declare(strict_types=1);

namespace App\Modules\User\Services;

use App\Modules\Core\Audit\AuditAction;
use App\Modules\Core\Contracts\AuditRecorderContract;
use App\Modules\User\Contracts\SocialIdentityRegistryContract;
use App\Modules\User\Data\SocialIdentityLink;
use App\Modules\User\Exceptions\SocialIdentityException;
use App\Modules\User\Models\SocialIdentity;
use App\Modules\User\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Links and unlinks social identities, and records that it did (ADR 0050 §5, §9).
 *
 * ## Every change locks the account first
 *
 * `AccountTypeManager::promote()` takes the same row lock, so a link and a promotion of
 * one account serialise: whichever runs second sees what the first committed. The
 * database triggers enforce the same rules underneath, for the writes that never reach
 * this class.
 *
 * ## What is recorded
 *
 * The provider, the identity's identifier, and what changed — never the address and
 * never the provider's subject, both of which identify a person. The records commit with
 * the change they describe, inside its transaction (ADR 0037).
 */
class SocialIdentityRegistry implements SocialIdentityRegistryContract
{
    public function __construct(private readonly AuditRecorderContract $audit) {}

    public function findBySubject(string $provider, string $subject): ?SocialIdentity
    {
        return SocialIdentity::query()
            ->where('provider', $provider)
            ->where('provider_subject', $subject)
            ->first();
    }

    public function hasLinkedIdentity(User $user): bool
    {
        return SocialIdentity::query()->linked()->where('user_id', $user->id)->exists();
    }

    public function linkedIdentities(User $user): Collection
    {
        return SocialIdentity::query()
            ->linked()
            ->where('user_id', $user->id)
            ->orderBy('provider')
            ->get();
    }

    public function link(User $user, string $provider, string $subject, ?string $email): SocialIdentityLink
    {
        try {
            return DB::transaction(function () use ($user, $provider, $subject, $email): SocialIdentityLink {
                $owner = $this->lockAccount($user);

                if ($owner->isAdmin()) {
                    throw SocialIdentityException::administrator();
                }

                $existing = SocialIdentity::query()
                    ->where('provider', $provider)
                    ->where('provider_subject', $subject)
                    ->lockForUpdate()
                    ->first();

                // A subject belongs to one account for good, including after it was
                // unlinked from that account.
                if ($existing !== null && $existing->user_id !== $owner->id) {
                    throw SocialIdentityException::identityInUse();
                }

                if ($existing !== null && $existing->isLinked()) {
                    return new SocialIdentityLink($existing, relinked: false, alreadyLinked: true);
                }

                $otherLinkedFromProvider = SocialIdentity::query()
                    ->linked()
                    ->where('user_id', $owner->id)
                    ->where('provider', $provider)
                    ->exists();

                if ($otherLinkedFromProvider) {
                    throw SocialIdentityException::providerAlreadyLinked();
                }

                if ($existing !== null) {
                    // Re-linking reuses the historical row: the subject never gains a
                    // second row, and the history of who held it stays in one place.
                    $existing->forceFill([
                        'unlinked_at' => null,
                        'linked_at' => now(),
                        'email' => $email ?? $existing->email,
                    ])->save();

                    $this->recordLinked($owner, $existing, relinked: true);

                    return new SocialIdentityLink($existing->refresh(), relinked: true, alreadyLinked: false);
                }

                $identity = SocialIdentity::query()->create([
                    'user_id' => $owner->id,
                    'provider' => $provider,
                    'provider_subject' => $subject,
                    'email' => $email,
                    'linked_at' => now(),
                ]);

                $this->recordLinked($owner, $identity, relinked: false);

                return new SocialIdentityLink($identity, relinked: false, alreadyLinked: false);
            });
        } catch (UniqueConstraintViolationException) {
            // Another request claimed this subject, or linked this provider, in the same
            // instant. The constraint decided; this only says which rule it was.
            throw $this->findBySubject($provider, $subject)?->user_id === $user->id
                ? SocialIdentityException::providerAlreadyLinked()
                : SocialIdentityException::identityInUse();
        }
    }

    public function unlink(User $user, string $identityId): SocialIdentity
    {
        return DB::transaction(function () use ($user, $identityId): SocialIdentity {
            $owner = $this->lockAccount($user);

            $identity = SocialIdentity::query()
                ->linked()
                ->whereKey($identityId)
                ->where('user_id', $owner->id)
                ->lockForUpdate()
                ->first();

            if ($identity === null) {
                throw SocialIdentityException::notLinked();
            }

            $remaining = SocialIdentity::query()
                ->linked()
                ->where('user_id', $owner->id)
                ->whereKeyNot($identity->id)
                ->count();

            // An account with no password signs in only through its identities. The last
            // one cannot go, or nothing could ever sign in to it again.
            if ($owner->password === null && $remaining === 0) {
                throw SocialIdentityException::lastSignInMethod();
            }

            $identity->forceFill(['unlinked_at' => now()])->save();

            $this->audit->succeeded(AuditAction::ACCOUNT_SOCIAL_UNLINKED, $owner->id, [
                'provider' => $identity->provider,
                'identity_id' => $identity->id,
                'linked_remaining' => $remaining,
            ]);

            return $identity->refresh();
        });
    }

    public function recordUse(SocialIdentity $identity): void
    {
        SocialIdentity::query()->whereKey($identity->id)->update(['last_used_at' => now()]);
    }

    private function lockAccount(User $user): User
    {
        /** @var User */
        return User::query()->whereKey($user->getKey())->lockForUpdate()->firstOrFail();
    }

    private function recordLinked(User $owner, SocialIdentity $identity, bool $relinked): void
    {
        $this->audit->succeeded(AuditAction::ACCOUNT_SOCIAL_LINKED, $owner->id, [
            'provider' => $identity->provider,
            'identity_id' => $identity->id,
            'relinked' => $relinked,
        ]);
    }
}
