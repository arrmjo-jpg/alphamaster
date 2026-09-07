<?php

declare(strict_types=1);

namespace App\Modules\Auth\Services;

use App\Modules\Auth\Contracts\AuthServiceContract;
use App\Modules\Auth\Contracts\MfaManagerContract;
use App\Modules\Auth\Data\AuthenticatedToken;
use App\Modules\Auth\Enums\TokenAbility;
use App\Modules\Auth\Exceptions\AccountInactiveException;
use App\Modules\Auth\Exceptions\InvalidCredentialsException;
use App\Modules\Auth\Exceptions\MfaChallengeException;
use App\Modules\Auth\Exceptions\UnverifiedAdministratorException;
use App\Modules\Auth\Support\LoginIdentifier;
use App\Modules\Core\Cache\CacheNamespace;
use App\Modules\Core\Contracts\PlatformCacheContract;
use App\Modules\User\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class AuthService implements AuthServiceContract
{
    /**
     * Prefix for the temporary MFA challenge entries.
     */
    /**
     * The challenge resource, in the platform's only fail-closed namespace: here the
     * cache *is* the source of truth for whether a challenge was issued, so a store
     * that cannot be read must raise rather than read as "no challenge outstanding"
     * (ADR 0035).
     */
    private const MFA_CHALLENGE_RESOURCE = 'mfa_challenge';

    /**
     * How long a half-authenticated challenge stays valid.
     */
    public const MFA_CHALLENGE_TTL = 300; // 5 minutes

    public function __construct(
        private readonly MfaManagerContract $mfa,
        private readonly PlatformCacheContract $cache,
    ) {}

    /**
     * Verify credentials and return the user, without issuing anything.
     *
     * @throws InvalidCredentialsException
     * @throws AccountInactiveException
     */
    public function authenticate(string $identifier, string $password): User
    {
        // Which column to look in, decided by the identifier itself. An E.164 number
        // cannot contain `@` and an email address cannot omit one, so the two kinds
        // never overlap and neither lookup can shadow the other.
        //
        // The phone side goes through findByPhone(), which matches on the keyed hash
        // rather than on the text, so `+962 79 000 0000` and `+962790000000` resolve
        // to the same account — the same equivalence the unique constraint enforces.
        // It answers null rather than raising for anything it cannot read, which is
        // what keeps an unreadable identifier indistinguishable from an unknown one.
        $user = LoginIdentifier::isEmail($identifier)
            ? User::query()->where('email', mb_strtolower($identifier))->first()
            : User::findByPhone($identifier);

        // Hash a dummy value when the account is unknown, so a missing account and a
        // wrong password take comparable time and cannot be told apart by timing.
        if ($user === null) {
            Hash::check($password, '$2y$12$'.str_repeat('0', 53));

            throw new InvalidCredentialsException;
        }

        if (! Hash::check($password, $user->password)) {
            throw new InvalidCredentialsException;
        }

        // The active boundary is enforced here as well as in middleware: a suspended
        // account must not be able to obtain a token in the first place.
        if (! $user->is_active) {
            throw new AccountInactiveException;
        }

        return $user;
    }

    /**
     * Whether this user must clear an MFA challenge before receiving a token.
     */
    public function requiresMfa(User $user): bool
    {
        return $this->mfa->isEnabled($user);
    }

    /**
     * Whether this user must enrol a second factor before receiving a token at all.
     *
     * MFA is mandatory for administrators (ADR 0013) and optional for everyone else,
     * so an administrator who has not enrolled is stopped at sign-in and handed an
     * enrolment credential instead of access.
     */
    public function requiresMfaEnrolment(User $user): bool
    {
        return $user->isAdmin() && ! $this->mfa->satisfiesPolicy($user);
    }

    /**
     * Whether this user must verify their email address before receiving a token.
     *
     * Administrators only. A verified address is what makes the account recoverable
     * and what an audit trail attributes an action to; for an account that can change
     * the platform's configuration, an unverified one is an unowned identity.
     * Everyone else may verify and is not stopped for not having.
     */
    public function requiresEmailVerification(User $user): bool
    {
        return $user->isAdmin() && ! $user->hasVerifiedEmail();
    }

    /**
     * Issue a token that can do nothing but ask for a verification link.
     *
     * The same construction as the enrolment credential and for the same reason: a
     * real Sanctum token carrying one narrow ability, so the perimeter that already
     * exists does the enforcing and no second path has to be kept in step.
     */
    public function issueEmailVerificationToken(User $user): AuthenticatedToken
    {
        return new AuthenticatedToken(
            $user,
            $user->createToken('email-verification', [TokenAbility::EMAIL_VERIFY->value])->plainTextToken,
            TokenAbility::EMAIL_VERIFY,
        );
    }

    /**
     * Issue a token that can do nothing but enrol a second factor.
     *
     * Deliberately a real Sanctum token rather than another bespoke credential: the
     * ability layer already refuses it everywhere that matters, so mandatory enrolment
     * needs no new enforcement path.
     */
    public function issueEnrolmentToken(User $user): AuthenticatedToken
    {
        return new AuthenticatedToken(
            $user,
            $user->createToken('mfa-enrolment', [TokenAbility::MFA_ENROL->value])->plainTextToken,
            TokenAbility::MFA_ENROL,
        );
    }

    /**
     * Issue a Sanctum token carrying exactly one ability, per ADR 0012.
     */
    public function issueToken(User $user, string $name = 'api-token'): AuthenticatedToken
    {
        $ability = TokenAbility::forAdministrator($user->isAdmin());

        // The choke point. Three paths mint an access token — sign-in, completing an
        // MFA challenge, and exchanging an enrolment credential — and all three come
        // through here, so the invariant is stated once instead of three times and
        // cannot be missed by a fourth.
        //
        // Unreachable in ordinary operation: sign-in refuses an unverified
        // administrator before it gets this far. That is what makes throwing the right
        // response rather than a harsh one — arriving here means a path exists that
        // nobody intended, and the useful outcome is a loud failure rather than a
        // token.
        if ($ability === TokenAbility::ADMIN_ACCESS && ! $user->hasVerifiedEmail()) {
            throw UnverifiedAdministratorException::cannotHoldAdminAccess($user->id);
        }

        return new AuthenticatedToken(
            $user,
            $user->createToken($name, [$ability->value])->plainTextToken,
            $ability,
        );
    }

    /**
     * Start an MFA challenge and return the opaque token the client must present.
     *
     * Only a hash of the token is stored, so the cache never holds a credential
     * that would be usable if the store were read.
     */
    public function startMfaChallenge(User $user): string
    {
        $token = Str::random(64);

        $this->cache->put(
            CacheNamespace::AUTH,
            self::MFA_CHALLENGE_RESOURCE,
            [hash('sha256', $token)],
            $user->id,
            self::MFA_CHALLENGE_TTL,
        );

        return $token;
    }

    /**
     * Resolve the user behind a challenge token.
     *
     * @throws MfaChallengeException
     */
    public function resolveMfaChallenge(string $token): User
    {
        $userId = $this->cache->get(CacheNamespace::AUTH, self::MFA_CHALLENGE_RESOURCE, [hash('sha256', $token)]);

        if (! is_string($userId)) {
            throw new MfaChallengeException('api.error.auth.mfa_challenge_expired');
        }

        $user = User::query()->find($userId);

        if ($user === null) {
            throw new MfaChallengeException('api.error.auth.mfa_challenge_expired');
        }

        if (! $user->is_active) {
            $this->forgetMfaChallenge($token);

            throw new AccountInactiveException;
        }

        return $user;
    }

    /**
     * Invalidate a challenge token, so it cannot be presented twice.
     */
    public function forgetMfaChallenge(string $token): void
    {
        $this->cache->forget(CacheNamespace::AUTH, self::MFA_CHALLENGE_RESOURCE, [hash('sha256', $token)]);
    }

    /**
     * Complete a challenge and issue the real token.
     *
     * @throws MfaChallengeException
     */
    public function completeMfaChallenge(string $token, string $code): AuthenticatedToken
    {
        $user = $this->resolveMfaChallenge($token);

        if (! $this->mfa->verifyChallenge($user, $code)) {
            throw new MfaChallengeException('api.error.auth.mfa_code_invalid');
        }

        // Single use: a cleared challenge cannot be replayed even within its TTL.
        $this->forgetMfaChallenge($token);

        return $this->issueToken($user);
    }
}
