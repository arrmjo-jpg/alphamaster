<?php

declare(strict_types=1);

namespace App\Modules\Auth\Console;

use App\Modules\Auth\Models\PhoneSignInCode;
use App\Modules\Auth\Models\PhoneVerification;
use Illuminate\Console\Command;

/**
 * Removes one-time codes that can no longer be answered (ADR 0051 §6).
 *
 * An expired code is already refused, so this changes no outcome. It keeps rows that
 * describe which numbers were sent a code from outliving their only use.
 */
class PruneOneTimeCodesCommand extends Command
{
    protected $signature = 'auth:prune-codes';

    protected $description = 'Remove expired phone sign-in and phone verification codes';

    public function handle(): int
    {
        $signIn = PhoneSignInCode::query()->where('expires_at', '<', now())->delete();
        $verification = PhoneVerification::query()->where('expires_at', '<', now())->delete();

        $this->info("Removed {$signIn} sign-in code(s) and {$verification} verification code(s).");

        return self::SUCCESS;
    }
}
