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
     * The identifier is an email address or a phone number. Which one it is is the
     * implementation's question, not the caller's: a sign-in form has one field and
     * the person filling it in does not tell the server which kind of thing they
     * typed.
     *
     * @throws InvalidCredentialsException
     * @throws AccountInactiveException
     */
    public function authenticate(string $identifier, string $password): User;

    /**
     * Whether this user must clear an MFA challenge before receiving a token.
     */
    public function requiresMfa(User $user): bool;

    /**
     * Whether this user must enrol a second factor before receiving a token at all.
     */
    public function requiresMfaEnrolment(User $user): bool;

    /**
     * Whether this user must verify their email address before receiving a token.
     */
    public function requiresEmailVerification(User $user): bool;

    /**
     * Issue a token scoped to MFA enrolment and nothing else.
     */
    public function issueEnrolmentToken(User $user): AuthenticatedToken;

    /**
     * Issue a token scoped to requesting a verification link and nothing else.
     */
    public function issueEmailVerificationToken(User $user): AuthenticatedToken;

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
