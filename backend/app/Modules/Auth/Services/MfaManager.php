<?php

declare(strict_types=1);

namespace App\Modules\Auth\Services;

use App\Modules\Auth\Contracts\DeliversMfaCodes;
use App\Modules\Auth\Contracts\MfaManagerContract;
use App\Modules\Auth\Contracts\MfaMethodContract;
use App\Modules\Auth\Data\MfaEnrolment;
use App\Modules\Auth\Enums\MfaType;
use App\Modules\Auth\Exceptions\MfaDeliveryException;
use App\Modules\Auth\Exceptions\MfaEnrolmentException;
use App\Modules\Auth\Models\MfaMethod;
use App\Modules\Auth\Models\MfaRecoveryCode;
use App\Modules\User\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class MfaManager implements MfaManagerContract
{
    /**
     * How many recovery codes are issued when a method is confirmed.
     */
    public const RECOVERY_CODE_COUNT = 8;

    /**
     * Bytes of entropy per recovery code, before base32-ish encoding.
     */
    private const RECOVERY_CODE_BYTES = 10;

    /**
     * @param  array<string, MfaMethodContract>  $methods  keyed by MfaType value
     */
    public function __construct(private readonly array $methods) {}

    public function driver(MfaType $type): MfaMethodContract
    {
        $driver = $this->methods[$type->value] ?? null;

        if ($driver === null) {
            throw new MfaEnrolmentException("No MFA driver is registered for [{$type->value}].");
        }

        return $driver;
    }

    public function isEnabled(User $user): bool
    {
        return MfaMethod::query()
            ->where('user_id', $user->id)
            ->confirmed()
            ->exists();
    }

    /**
     * @param  array<string, mixed>  $options
     */
    public function enrol(User $user, MfaType $type, array $options = []): MfaEnrolment
    {
        // An administrator's second factor must be one that satisfies the mandatory
        // policy. Allowing them to enrol only SMS would let the accounts we protect
        // most fall back to the weakest factor available (ADR 0013).
        if ($user->isAdmin() && ! $type->satisfiesAdministratorPolicy()) {
            throw new MfaEnrolmentException(
                "Administrators cannot use [{$type->value}] as their second factor. Enrol an authenticator app instead."
            );
        }

        if ($this->hasConfirmedMethod($user, $type)) {
            throw new MfaEnrolmentException('This multi-factor method is already enabled. Disable it before enrolling again.');
        }

        return $this->driver($type)->enrol($user, $options);
    }

    /**
     * Whether the user has this specific method confirmed.
     */
    public function hasConfirmedMethod(User $user, MfaType $type): bool
    {
        return MfaMethod::query()
            ->where('user_id', $user->id)
            ->where('type', $type->value)
            ->confirmed()
            ->exists();
    }

    /**
     * Whether the user satisfies the policy that applies to their account type.
     *
     * An administrator needs a method strong enough for the mandatory requirement;
     * anyone else needs any confirmed method at all.
     */
    public function satisfiesPolicy(User $user): bool
    {
        if (! $user->isAdmin()) {
            return $this->isEnabled($user);
        }

        return MfaMethod::query()
            ->where('user_id', $user->id)
            ->whereIn('type', array_map(
                static fn (MfaType $t): string => $t->value,
                array_filter(MfaType::cases(), static fn (MfaType $t): bool => $t->satisfiesAdministratorPolicy())
            ))
            ->confirmed()
            ->exists();
    }

    /**
     * Deliver a code for the user's confirmed delivery-based method, if they have one.
     *
     * Returns the masked destination, or null when no method needs delivery — TOTP
     * users are answered by their authenticator and nothing is sent.
     *
     * @throws MfaDeliveryException
     */
    public function deliverChallenge(User $user): ?string
    {
        $method = MfaMethod::query()
            ->where('user_id', $user->id)
            ->confirmed()
            ->get()
            ->first(fn (MfaMethod $m): bool => $this->driver($m->type) instanceof DeliversMfaCodes);

        if ($method === null) {
            return null;
        }

        /** @var DeliversMfaCodes $driver */
        $driver = $this->driver($method->type);

        // A cooldown keeps a resend from turning the endpoint into an SMS amplifier
        // against the number's owner.
        if ($method->otp_sent_at !== null
            && $method->otp_sent_at->diffInSeconds(now()) < $driver->resendCooldownSeconds()) {
            throw new MfaDeliveryException(
                'A code was sent moments ago. Wait before requesting another.'
            );
        }

        return $driver->deliver($user, $method);
    }

    /**
     * @return array<int, string>
     */
    public function confirm(User $user, MfaType $type, string $code): array
    {
        $method = MfaMethod::query()
            ->where('user_id', $user->id)
            ->where('type', $type->value)
            ->first();

        if ($method === null) {
            throw new MfaEnrolmentException('There is no pending enrolment to confirm. Start enrolment first.');
        }

        if ($method->isConfirmed()) {
            throw new MfaEnrolmentException('This multi-factor method is already confirmed.');
        }

        if (! $this->driver($type)->verify($user, $code)) {
            throw new MfaEnrolmentException('The provided code is not valid for this enrolment.');
        }

        return DB::transaction(function () use ($user, $method): array {
            $method->forceFill(['confirmed_at' => now()])->save();

            return $this->regenerateRecoveryCodes($user);
        });
    }

    public function disable(User $user): void
    {
        DB::transaction(function () use ($user): void {
            MfaMethod::query()->where('user_id', $user->id)->delete();
            MfaRecoveryCode::query()->where('user_id', $user->id)->delete();
        });
    }

    /**
     * Verify a challenge response against any confirmed method, then against the
     * unused recovery codes. A matching recovery code is consumed atomically.
     */
    public function verifyChallenge(User $user, string $code): bool
    {
        $confirmed = MfaMethod::query()
            ->where('user_id', $user->id)
            ->confirmed()
            ->get();

        foreach ($confirmed as $method) {
            if ($this->driver($method->type)->verify($user, $code)) {
                return true;
            }
        }

        return $this->consumeRecoveryCode($user, $code);
    }

    /**
     * Issue a fresh set of recovery codes, invalidating any previous set.
     *
     * Only hashes are persisted; the plaintext returned here is the sole time the
     * codes are ever available.
     *
     * @return array<int, string>
     */
    public function regenerateRecoveryCodes(User $user): array
    {
        MfaRecoveryCode::query()->where('user_id', $user->id)->delete();

        $plaintext = [];

        for ($i = 0; $i < self::RECOVERY_CODE_COUNT; $i++) {
            $code = $this->generateRecoveryCode();
            $plaintext[] = $code;

            MfaRecoveryCode::query()->create([
                'user_id' => $user->id,
                'code_hash' => Hash::make($code),
            ]);
        }

        return $plaintext;
    }

    /**
     * Consume a recovery code if it matches an unused one.
     *
     * The match and the consumption happen inside a transaction, and the update is
     * conditional on the row still being unused, so two concurrent requests cannot
     * both spend the same code.
     */
    private function consumeRecoveryCode(User $user, string $code): bool
    {
        return DB::transaction(function () use ($user, $code): bool {
            $candidates = MfaRecoveryCode::query()
                ->where('user_id', $user->id)
                ->unused()
                ->lockForUpdate()
                ->get();

            foreach ($candidates as $candidate) {
                if (! Hash::check($code, $candidate->code_hash)) {
                    continue;
                }

                $consumed = MfaRecoveryCode::query()
                    ->whereKey($candidate->id)
                    ->whereNull('used_at')
                    ->update(['used_at' => now()]);

                return $consumed === 1;
            }

            return false;
        });
    }

    /**
     * A recovery code: uppercase, grouped, and unambiguous to transcribe.
     */
    private function generateRecoveryCode(): string
    {
        $raw = strtoupper(bin2hex(random_bytes(self::RECOVERY_CODE_BYTES)));

        return implode('-', str_split($raw, 5));
    }

    /**
     * Count the recovery codes still available to the user.
     */
    public function remainingRecoveryCodes(User $user): int
    {
        return MfaRecoveryCode::query()
            ->where('user_id', $user->id)
            ->unused()
            ->count();
    }
}
