<?php

declare(strict_types=1);

namespace App\Modules\Auth\Services;

use App\Modules\Auth\Exceptions\PasswordResetException;
use App\Modules\Core\Support\ClientUrlPolicy;
use App\Modules\User\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;

use function Illuminate\Support\defer;

/**
 * Getting back into an account by proving control of its address (ADR 0050 §12).
 *
 * The framework's password broker does the token work: a random token, stored hashed in
 * `password_reset_tokens`, valid for a limited time and usable once. This owns the three
 * decisions the broker leaves open.
 *
 * **Where the link points.** There is no public frontend, and no domain is assumed. The
 * link goes to `auth.password_reset_url`, which an operator sets to the client that
 * collects a new password. Unset, nothing is sent — a link to nowhere helps nobody.
 *
 * **What the caller learns.** Nothing about whether the address holds an account. The
 * request is answered the same way either way, and the mail is sent after the response,
 * so an address that exists and one that does not take the same time.
 *
 * **What a reset does to sessions.** Every token the account holds is revoked. Whoever
 * reset the password is the person who controls the address now; a session somebody else
 * opened with the old one must not outlive it.
 *
 * It covers an account that signs in only through a social provider as well as one with
 * a password: this is how a person who lost access to their provider sets one. It does
 * not verify the address — `email_verified_at` belongs to the signed verification link
 * (ADR 0012) and nothing here writes it.
 */
class PasswordRecoveryService
{
    /**
     * Why no reset link can be sent now, or null when one can.
     *
     * `missing` when the page is not set; otherwise a ClientUrlPolicy problem, judged in
     * this environment. A value that was fine when it was saved — before the environment
     * became production, or in a restored backup — is not trusted for being stored.
     */
    public function resetPageProblem(): ?string
    {
        $target = setting('auth.password_reset_url');

        if ($target === null || $target === '') {
            return 'missing';
        }

        return ClientUrlPolicy::forCurrentEnvironment()->pageUrlProblem($target);
    }

    /**
     * The page a reset link opens, or null when there is no usable one.
     */
    public function resetPageUrl(): ?string
    {
        $target = setting('auth.password_reset_url');

        return is_string($target) && $this->resetPageProblem() === null ? $target : null;
    }

    /**
     * Ask for a reset link to be sent to an address, if it belongs to an account.
     */
    public function requestLink(string $email): void
    {
        $problem = $this->resetPageProblem();

        if ($problem !== null) {
            Log::warning('Password recovery was requested, but auth.password_reset_url is not usable; no link was sent.', [
                'problem' => $problem,
            ]);

            return;
        }

        defer(static function () use ($email): void {
            Password::broker()->sendResetLink(['email' => $email]);
        });
    }

    /**
     * Set a new password with a reset token, and end every session the account had.
     *
     * @throws PasswordResetException
     */
    public function reset(string $email, string $token, string $password): void
    {
        $status = Password::broker()->reset(
            ['email' => $email, 'token' => $token, 'password' => $password],
            static function (User $user, string $password): void {
                DB::transaction(static function () use ($user, $password): void {
                    // Hashed by the model's cast; the plaintext never reaches the column.
                    $user->forceFill(['password' => $password])->save();

                    $user->tokens()->delete();
                });
            }
        );

        if ($status !== Password::PASSWORD_RESET) {
            throw new PasswordResetException;
        }
    }
}
