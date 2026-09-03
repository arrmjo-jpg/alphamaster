<?php

declare(strict_types=1);

namespace App\Modules\Auth\Contracts;

use App\Modules\Auth\Data\AuthenticatedToken;
use App\Modules\Auth\Exceptions\AccountInactiveException;
use App\Modules\Auth\Exceptions\InvalidCredentialsException;
use App\Modules\Auth\Exceptions\MfaChallengeException;
use App\Modules\User\Models\User;

interface AuthServiceContract
{
    /**
     * Verify credentials and return the user, without issuing anything.
     *
     * @throws InvalidCredentialsException
     * @throws AccountInactiveException
     */
    public function authenticate(string $email, string $password): User;

    /**
     * Whether this user must clear an MFA challenge before receiving a token.
     */
    public function requiresMfa(User $user): bool;

    /**
     * Issue a Sanctum token carrying exactly one ability, per ADR 0012.
     */
    public function issueToken(User $user, string $name = 'api-token'): AuthenticatedToken;

    /**
     * Start an MFA challenge and return the opaque token the client must present.
     */
    public function startMfaChallenge(User $user): string;

    /**
     * Resolve the user behind a challenge token.
     *
     * @throws MfaChallengeException
     */
    public function resolveMfaChallenge(string $token): User;

    /**
     * Invalidate a challenge token.
     */
    public function forgetMfaChallenge(string $token): void;

    /**
     * Complete a challenge and issue the real token.
     *
     * @throws MfaChallengeException
     */
    public function completeMfaChallenge(string $token, string $code): AuthenticatedToken;
}
