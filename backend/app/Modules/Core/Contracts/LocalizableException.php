<?php

declare(strict_types=1);

namespace App\Modules\Core\Contracts;

/**
 * An exception whose message is written for a person, and therefore has a
 * language.
 *
 * The exception names what went wrong; it does not decide which language to
 * say it in. `getMessage()` stays English for logs and for the diagnostics
 * this platform persists — an SMS usage row, a media record's failure reason —
 * which are written from queue workers where no request locale exists, the same
 * distinction ADR 0019 draws between a request's locale and a recipient's.
 * The API renders `translationKey()` against the caller's locale instead.
 *
 * Exceptions that report a technical fault rather than a human one do not
 * implement this: an unreadable ciphertext or a missing notification template
 * is a diagnostic, and localizing it would translate text no user reads.
 */
interface LocalizableException
{
    /**
     * The translation key for this exception's human-readable message.
     */
    public function translationKey(): string;

    /**
     * Values for the placeholders in that message.
     *
     * @return array<string, mixed>
     */
    public function translationParameters(): array;
}
