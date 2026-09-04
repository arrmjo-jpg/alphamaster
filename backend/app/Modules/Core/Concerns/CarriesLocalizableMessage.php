<?php

declare(strict_types=1);

namespace App\Modules\Core\Concerns;

/**
 * Renders an exception's message in English, whatever locale the request is in.
 *
 * `Exception::getMessage()` is final, so the message is fixed at construction —
 * which is the right place for it to be pinned. A queue worker writing a
 * failure reason onto a media record, or a driver error onto an SMS usage row,
 * must record the same sentence regardless of who happened to make the request
 * that enqueued it.
 *
 * The English text lives in the language files with every other message rather
 * than being repeated here, so a message has one source. If the key is missing
 * the key itself comes back, which is visible in a log rather than silent.
 */
trait CarriesLocalizableMessage
{
    /**
     * Values for the placeholders in this exception's message.
     *
     * @return array<string, mixed>
     */
    public function translationParameters(): array
    {
        return [];
    }

    /**
     * @param  array<string, mixed>  $parameters
     */
    protected static function englishMessage(string $key, array $parameters = []): string
    {
        $rendered = __($key, $parameters, 'en');

        return is_string($rendered) ? $rendered : $key;
    }
}
