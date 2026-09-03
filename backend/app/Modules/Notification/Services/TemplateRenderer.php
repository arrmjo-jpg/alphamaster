<?php

declare(strict_types=1);

namespace App\Modules\Notification\Services;

use App\Modules\Notification\Contracts\TemplateRendererContract;
use App\Modules\Notification\Data\RenderedNotification;
use App\Modules\Notification\Enums\NotificationType;
use App\Modules\Notification\Exceptions\MissingTemplateException;
use App\Modules\Notification\Models\NotificationTemplate;

/**
 * Turns a template plus a locale plus some values into the text a recipient receives.
 */
class TemplateRenderer implements TemplateRendererContract
{
    /**
     * Render in the requested locale.
     *
     * Locale fallback is the trait's business (ADR 0015): a partially translated
     * template degrades to the default language rather than to an empty message.
     *
     * @param  array<string, string|int>  $placeholders
     *
     * @throws MissingTemplateException
     */
    public function render(NotificationType $type, string $locale, array $placeholders = []): RenderedNotification
    {
        $template = NotificationTemplate::query()
            ->with('translations')
            ->active()
            ->where('type', $type->value)
            ->first();

        if ($template === null) {
            throw new MissingTemplateException($type);
        }

        $subject = $template->translate('subject', $locale);
        $body = $template->translate('body', $locale);

        if ($subject === null || $body === null) {
            throw new MissingTemplateException($type);
        }

        return new RenderedNotification(
            $type,
            $locale,
            $this->substitute($subject, $placeholders),
            $this->substitute($body, $placeholders),
        );
    }

    /**
     * Replace :name placeholders.
     *
     * Values are inserted literally and never interpreted, so a template cannot be
     * turned into a vehicle for whatever a value happens to contain. Unknown
     * placeholders are left in place rather than blanked, which makes a missing value
     * visible in the delivered message instead of silently producing a gap.
     *
     * @param  array<string, string|int>  $placeholders
     */
    private function substitute(string $text, array $placeholders): string
    {
        if ($placeholders === []) {
            return $text;
        }

        $replacements = [];

        foreach ($placeholders as $key => $value) {
            $replacements[':'.$key] = (string) $value;
        }

        // Longest keys first, so :user_name is not partly consumed by :user.
        uksort($replacements, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));

        return strtr($text, $replacements);
    }
}
