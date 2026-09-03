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
use App\Modules\User\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class AuthService implements AuthServiceContract
{
    /**
     * Prefix for the temporary MFA challenge entries.
     */
    private const MFA_CHALLENGE_PREFIX = 'auth:mfa:challenge:';

    /**
     * How long a half-authenticated challenge stays valid.
     */
    public const MFA_CHALLENGE_TTL = 300; // 5 minutes

    public function __construct(
        private readonly MfaManagerContract $mfa
    ) {}

    /**
     * Verify credentials and return the user, without issuing anything.
     *
     * @throws InvalidCredentialsException
     * @throws AccountInactiveException
     */
    public function authenticate(string $email, string $password): User
    {
        $user = User::query()->where('email', mb_strtolower($email))->first();

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
     * Issue a Sanctum token carrying exactly one ability, per ADR 0012.
     */
    public function issueToken(User $user, string $name = 'api-token'): AuthenticatedToken
    {
        $ability = TokenAbility::forAdministrator($user->is_admin);

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

        Cache::put(
            self::MFA_CHALLENGE_PREFIX.hash('sha256', $token),
            $user->id,
            self::MFA_CHALLENGE_TTL
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
        $userId = Cache::get(self::MFA_CHALLENGE_PREFIX.hash('sha256', $token));

        if (! is_string($userId)) {
            throw new MfaChallengeException('The multi-factor challenge is invalid or has expired.');
        }

        $user = User::query()->find($userId);

        if ($user === null) {
            throw new MfaChallengeException('The multi-factor challenge is invalid or has expired.');
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
        Cache::forget(self::MFA_CHALLENGE_PREFIX.hash('sha256', $token));
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
            throw new MfaChallengeException('The provided multi-factor code is not valid.');
        }

        // Single use: a cleared challenge cannot be replayed even within its TTL.
        $this->forgetMfaChallenge($token);

        return $this->issueToken($user);
    }
}
