<?php

declare(strict_types=1);

namespace App\Modules\Core\Content;

use RuntimeException;

/**
 * A change to localized content the platform will not make, with the stable code and status
 * a client matches on (ADR 0031, ADR 0055).
 *
 * Thrown by a content module's service and rendered by its controller, so the rule and the
 * refusal live together and a controller never re-derives either.
 */
final class ContentRefusedException extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $details
     */
    public function __construct(
        public readonly string $errorCode,
        public readonly string $messageKey,
        public readonly array $details = [],
        public readonly int $status = 422,
    ) {
        parent::__construct($errorCode);
    }

    /** The language is not one Language Management knows. */
    public static function unknownLocale(string $locale): self
    {
        return new self('UNKNOWN_CONTENT_LOCALE', 'api.error.content.unknown_locale', ['locale' => $locale]);
    }

    public static function slugInvalid(string $locale): self
    {
        return new self('CONTENT_SLUG_INVALID', 'api.error.content.slug_invalid', ['locale' => $locale]);
    }

    /** Another item already has this address in this language. */
    public static function slugTaken(string $locale, string $slug): self
    {
        return new self('CONTENT_SLUG_TAKEN', 'api.error.content.slug_taken', ['locale' => $locale, 'slug' => $slug], 409);
    }

    /** Publishing needs a complete translation in the default language. */
    public static function defaultTranslationIncomplete(string $defaultLocale): self
    {
        return new self('CONTENT_DEFAULT_TRANSLATION_INCOMPLETE', 'api.error.content.default_translation_incomplete', ['default_locale' => $defaultLocale], 409);
    }

    /** A published item cannot lose its default-language translation. */
    public static function defaultTranslationRequired(string $defaultLocale): self
    {
        return new self('CONTENT_DEFAULT_TRANSLATION_REQUIRED', 'api.error.content.default_translation_required', ['default_locale' => $defaultLocale], 409);
    }

    /** The referenced media is not a public image ready to serve. */
    public static function imageUnavailable(string $field): self
    {
        return new self('CONTENT_IMAGE_UNAVAILABLE', 'api.error.content.image_unavailable', ['field' => $field]);
    }
}
