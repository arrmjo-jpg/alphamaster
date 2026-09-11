<?php

declare(strict_types=1);

namespace App\Modules\Notification\Services;

use App\Modules\Core\Contracts\PushDeviceRegistrarContract;
use App\Modules\Notification\Models\PushDevice;

/**
 * The Core-facing view of the device registry.
 *
 * One method, because one thing outside this module has a reason to touch the
 * registry: signing out (ADR 0045 §5). Registration is the account's own act through
 * this module's own endpoint, and nothing else reaches in.
 */
class PushDeviceRegistrar implements PushDeviceRegistrarContract
{
    public function forgetForAccessToken(string $accessTokenId): void
    {
        if ($accessTokenId === '') {
            return;
        }

        PushDevice::query()->where('access_token_id', $accessTokenId)->delete();
    }
}
