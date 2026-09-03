<?php

declare(strict_types=1);

namespace App\Modules\Notification\Data;

use App\Modules\Notification\Enums\NotificationType;

/**
 * A template resolved to the text one recipient will actually receive.
 */
final readonly class RenderedNotification
{
    public function __construct(
        public NotificationType $type,
        public string $locale,
        public string $subject,
        public string $body,
    ) {}

    /**
     * @return array<string, string>
     */
    public function toArray(): array
    {
        return [
            'type' => $this->type->value,
            'locale' => $this->locale,
            'subject' => $this->subject,
            'body' => $this->body,
        ];
    }
}
