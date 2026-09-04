<?php

declare(strict_types=1);

namespace App\Modules\Notification\Exceptions;

use App\Modules\Core\Concerns\CarriesLocalizableMessage;
use App\Modules\Core\Contracts\LocalizableException;
use App\Modules\Notification\Enums\NotificationChannel;
use App\Modules\Notification\Enums\NotificationType;
use InvalidArgumentException;

/**
 * Raised when a preference update tries to silence a notification that the
 * platform does not allow to be silenced on that channel.
 *
 * It extends InvalidArgumentException because that is what the resolver raised
 * before it carried a message worth translating, and callers that catch the
 * broader type keep working.
 */
class PreferenceNotSilenceableException extends InvalidArgumentException implements LocalizableException
{
    use CarriesLocalizableMessage;

    public function __construct(
        public readonly NotificationType $type,
        public readonly NotificationChannel $channel
    ) {
        parent::__construct(self::englishMessage($this->translationKey(), $this->translationParameters()));
    }

    public function translationKey(): string
    {
        return 'api.error.notification.preference_not_silenceable';
    }

    public function translationParameters(): array
    {
        return ['type' => $this->type->value, 'channel' => $this->channel->value];
    }
}
