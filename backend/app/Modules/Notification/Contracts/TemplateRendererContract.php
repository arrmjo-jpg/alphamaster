<?php

declare(strict_types=1);

namespace App\Modules\Notification\Contracts;

use App\Modules\Notification\Data\RenderedNotification;
use App\Modules\Notification\Enums\NotificationType;

interface TemplateRendererContract
{
    /**
     * Render a notification's subject and body in the given locale.
     *
     * @param  array<string, string|int>  $placeholders
     */
    public function render(NotificationType $type, string $locale, array $placeholders = []): RenderedNotification;
}
