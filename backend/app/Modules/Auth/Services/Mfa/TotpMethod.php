<?php

declare(strict_types=1);

namespace App\Modules\Auth\Services\Mfa;

use App\Modules\Auth\Contracts\MfaMethodContract;
use App\Modules\Auth\Enums\MfaType;
use App\Modules\Auth\Models\MfaMethod;
use App\Modules\User\Models\User;
use PragmaRX\Google2FA\Google2FA;

/**
 * Time-based one-time passwords (RFC 6238) via pragmarx/google2fa.
 */
class TotpMethod implements MfaMethodContract
{
    /**
     * Number of 30-second steps tolerated either side of the current one, to
     * absorb clock drift between the server and the authenticator app.
     */
    private const WINDOW = 1;

    public function __construct(private readonly Google2FA $google2fa) {}

    public function type(): MfaType
    {
        return MfaType::TOTP;
    }

    /**
     * Generate a fresh secret and the otpauth:// URI an authenticator app scans.
     *
     * Replaces any unconfirmed enrolment so a restarted setup does not leave a
     * stale secret behind.
     *
     * @return array{secret: string, uri: string}
     */
    public function enrol(User $user): array
    {
        $secret = $this->google2fa->generateSecretKey();

        $method = MfaMethod::query()->firstOrNew([
            'user_id' => $user->id,
            'type' => MfaType::TOTP->value,
        ]);

        $method->type = MfaType::TOTP;
        $method->confirmed_at = null;
        $method->last_used_slice = null;
        $method->last_used_at = null;
        $method->setSecret($secret);
        $method->save();

        return [
            'secret' => $secret,
            'uri' => $this->google2fa->getQRCodeUrl(
                (string) config('app.name'),
                $user->email,
                $secret
            ),
        ];
    }

    /**
     * Verify a submitted code against the user's stored secret.
     *
     * A code is accepted only from a time slice strictly newer than the last one
     * accepted, so a code observed in transit cannot be replayed inside its own
     * validity window.
     */
    public function verify(User $user, string $code): bool
    {
        $method = MfaMethod::query()
            ->where('user_id', $user->id)
            ->where('type', MfaType::TOTP->value)
            ->first();

        if ($method === null) {
            return false;
        }

        // verifyKeyNewer returns the matched slice index only when given a previous
        // one; passing the oldest slice still inside the window keeps the search
        // span identical to a null baseline while still yielding that index.
        $baseline = $method->last_used_slice
            ?? ($this->google2fa->getTimestamp() - self::WINDOW - 1);

        $matchedSlice = $this->google2fa->verifyKeyNewer(
            $method->getSecret(),
            $code,
            $baseline,
            self::WINDOW
        );

        if ($matchedSlice === false || $matchedSlice === true) {
            // `true` cannot occur here (a baseline is always supplied) and would
            // leave the slice unknown, so it is refused rather than guessed at.
            return false;
        }

        $method->forceFill([
            'last_used_slice' => (int) $matchedSlice,
            'last_used_at' => now(),
        ])->save();

        return true;
    }
}
