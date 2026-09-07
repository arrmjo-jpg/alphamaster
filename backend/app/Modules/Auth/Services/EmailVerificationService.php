<?php

declare(strict_types=1);

namespace App\Modules\Auth\Services;

use App\Modules\Auth\Exceptions\EmailVerificationThrottledException;
use App\Modules\Auth\Exceptions\InvalidVerificationLinkException;
use App\Modules\Core\Cache\CacheNamespace;
use App\Modules\Core\Contracts\PlatformCacheContract;
use App\Modules\User\Models\User;

/**
 * Sending a verification link, and honouring one that comes back.
 *
 * The link itself is the framework's: `Illuminate\Auth\Notifications\VerifyEmail`
 * builds a temporary signed URL to the `verification.verify` route carrying the
 * account id and a SHA-1 of the address it was issued for. Nothing here reimplements
 * that, and nothing here goes through the Notification module — the architecture
 * suite forbids Auth from importing it, and the framework's own notification crosses
 * no module boundary at all.
 *
 * What this owns is the two decisions the framework leaves open: how often a link may
 * be asked for, and what makes a returned link no longer honourable.
 */
class EmailVerificationService
{
    /**
     * How long a caller must wait before asking for another link.
     *
     * Matched to the SMS resend cooldown of ADR 0013 rather than picked afresh: both
     * exist because a send endpoint reaches a real inbox belonging to someone who did
     * not ask, and a client retrying on a spinner should not be able to do it either.
     */
    public const RESEND_COOLDOWN_SECONDS = 60;

    private const COOLDOWN_RESOURCE = 'email_verification_cooldown';

    public function __construct(private readonly PlatformCacheContract $cache) {}

    /**
     * Send a verification link, unless one was sent too recently.
     *
     * Answers whether anything was sent. An already-verified address is not an error
     * — a second click on a stale link, or two tabs, arrive here legitimately — but it
     * is also not a reason to send mail.
     *
     * @throws EmailVerificationThrottledException
     */
    public function send(User $user): bool
    {
        if ($user->hasVerifiedEmail()) {
            return false;
        }

        $remaining = $this->secondsUntilResendAllowed($user);

        if ($remaining > 0) {
            throw new EmailVerificationThrottledException($remaining);
        }

        // The cooldown is recorded before the send rather than after it. A mail
        // transport that hangs would otherwise leave the endpoint wide open for
        // exactly as long as it hangs, which is when it matters most.
        $this->cache->put(
            CacheNamespace::AUTH,
            self::COOLDOWN_RESOURCE,
            [$user->id],
            now()->addSeconds(self::RESEND_COOLDOWN_SECONDS)->getTimestamp(),
            self::RESEND_COOLDOWN_SECONDS,
        );

        $user->sendEmailVerificationNotification();

        return true;
    }

    /**
     * Seconds left on the cooldown, or zero when a link may be sent.
     */
    public function secondsUntilResendAllowed(User $user): int
    {
        $until = $this->cache->get(CacheNamespace::AUTH, self::COOLDOWN_RESOURCE, [$user->id]);

        // `is_numeric` rather than `is_int`, and the difference is not cosmetic. The
        // platform cache round-trips an integer as a string — put(12345) reads back
        // '12345' — so a strict check here is always false and the cooldown never
        // fires. It fails in the safe-looking direction, which is why it needs saying:
        // nothing errors, the endpoint simply stops being throttled.
        if (! is_numeric($until)) {
            return 0;
        }

        return max(0, (int) $until - now()->getTimestamp());
    }

    /**
     * Honour a returned link, and answer whether this call is what verified it.
     *
     * The signature has already been checked by middleware before this runs, which
     * establishes that the link came from here and has not expired. Two things it
     * does not establish are checked here.
     *
     * The account must still exist. And the address must still be the one the link
     * was issued for: the hash is of the address at issue time, so a link signed
     * before an address changed remains validly signed, and honouring it would mark a
     * *new* address verified on the strength of a mail delivered to the old one.
     *
     * Verifying twice is not an error. A mail client that prefetches links, a user who
     * clicks twice, and a browser that retries all arrive here a second time, and the
     * honest answer is that the address is verified — with the original timestamp
     * left alone, because it records when verification happened rather than when it
     * was last confirmed.
     *
     * @return array{user: User, verified_now: bool}
     *
     * @throws InvalidVerificationLinkException
     */
    public function verify(string $id, string $hash): array
    {
        $user = User::query()->find($id);

        if ($user === null) {
            throw new InvalidVerificationLinkException;
        }

        // hash_equals rather than ===: the comparison is against a value an attacker
        // supplies, and constant time costs nothing here.
        if (! hash_equals(sha1($user->getEmailForVerification()), $hash)) {
            throw new InvalidVerificationLinkException;
        }

        if ($user->hasVerifiedEmail()) {
            return ['user' => $user, 'verified_now' => false];
        }

        $user->markEmailAsVerified();

        // The cooldown has served its purpose the moment the address is verified;
        // leaving it would only delay a link the account can no longer need.
        $this->cache->forget(CacheNamespace::AUTH, self::COOLDOWN_RESOURCE, [$user->id]);

        return ['user' => $user->refresh(), 'verified_now' => true];
    }
}
