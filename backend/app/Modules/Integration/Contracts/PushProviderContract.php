<?php

declare(strict_types=1);

namespace App\Modules\Integration\Contracts;

use App\Modules\Integration\Data\PushMessage;
use App\Modules\Integration\Data\PushResult;
use App\Modules\Integration\Models\IntegrationProvider;

/**
 * One push vendor.
 *
 * The same shape `SmsProviderContract` has, because push is the same kind of thing: a
 * transport whose choice the recipient cannot detect. Configuration comes from the
 * database, a refusal is returned rather than thrown, and the driver is written
 * against Laravel's HTTP client so it is exercisable without anybody's credentials.
 */
interface PushProviderContract
{
    public function driver(): string;

    public function send(PushMessage $message, IntegrationProvider $provider): PushResult;
}
