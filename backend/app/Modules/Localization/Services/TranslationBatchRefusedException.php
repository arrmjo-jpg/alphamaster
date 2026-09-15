<?php

declare(strict_types=1);

namespace App\Modules\Localization\Services;

use App\Modules\Core\Translation\TranslationRefusedException;
use App\Modules\Core\Translation\UnknownTranslationTargetException;
use RuntimeException;

/**
 * Why one item's translation was not accepted, in the shape a response reports it.
 *
 * Thrown by the acceptance itself rather than decided in a controller, so accepting one item and
 * accepting every ready item give the same reasons — the second reports them per item instead of
 * stopping at the first.
 */
final class TranslationBatchRefusedException extends RuntimeException
{
    /**
     * @param  array<string, string>  $parameters
     */
    private function __construct(
        public readonly string $errorCode,
        public readonly string $messageKey,
        public readonly int $status,
        public readonly array $parameters = [],
    ) {
        parent::__construct($messageKey);
    }

    public static function unknownSource(string $source): self
    {
        return new self('UNKNOWN_TRANSLATION_SOURCE', 'api.error.translations.unknown_source', 404, ['source' => $source]);
    }

    public static function forbidden(string $source): self
    {
        return new self('FORBIDDEN', 'api.error.translations.write_refused', 403, ['source' => $source]);
    }

    public static function notReady(string $status): self
    {
        return new self('TRANSLATION_NOT_READY', 'api.error.translations.batch_not_ready', 409, ['status' => $status]);
    }

    public static function moved(): self
    {
        return new self('TRANSLATION_MOVED', 'api.error.translations.batch_stale', 409);
    }

    public static function unknownField(string $field): self
    {
        return new self('UNKNOWN_TRANSLATION_FIELD', 'api.error.translations.batch_unknown_field', 422, ['field' => $field]);
    }

    public static function tooLong(string $field, int $length, int $max): self
    {
        return new self('TRANSLATION_TOO_LONG', 'api.error.translations.too_long', 422, [
            'field' => $field,
            'length' => (string) $length,
            'max' => (string) $max,
        ]);
    }

    public static function placeholdersChanged(string $field): self
    {
        return new self('PLACEHOLDERS_CHANGED', 'api.error.translations.placeholders_changed', 422, ['field' => $field]);
    }

    public static function requiredEmpty(string $field): self
    {
        return new self('TRANSLATION_REQUIRED_EMPTY', 'api.error.translations.required_empty', 422, ['field' => $field]);
    }

    public static function refused(TranslationRefusedException $e): self
    {
        return new self('TRANSLATION_REFUSED', $e->translationKey(), 422, self::strings($e->translationParameters()));
    }

    public static function unknownTarget(UnknownTranslationTargetException $e): self
    {
        return new self('UNKNOWN_TRANSLATION_TARGET', $e->translationKey(), 404, self::strings($e->translationParameters()));
    }

    /**
     * The reason in the caller's language.
     */
    public function reason(): string
    {
        return (string) __($this->messageKey, $this->parameters);
    }

    /**
     * @param  array<array-key, mixed>  $parameters
     * @return array<string, string>
     */
    private static function strings(array $parameters): array
    {
        $strings = [];

        foreach ($parameters as $key => $value) {
            $strings[(string) $key] = is_scalar($value) ? (string) $value : '';
        }

        return $strings;
    }
}
