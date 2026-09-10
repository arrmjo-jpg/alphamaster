<?php

declare(strict_types=1);

namespace App\Modules\Core\Translation;

use App\Modules\Core\Concerns\CarriesLocalizableMessage;
use App\Modules\Core\Contracts\LocalizableException;
use RuntimeException;

/**
 * Raised when a write names an item or a field the source does not have.
 *
 * A refusal rather than a no-op. The workshop addresses items by ids it was handed a
 * moment earlier, so a miss means the content moved underneath the editor — and
 * quietly discarding the text somebody just typed is the worst available answer.
 */
class UnknownTranslationTargetException extends RuntimeException implements LocalizableException
{
    use CarriesLocalizableMessage;

    /**
     * @param  array<string, string|int>  $parameters
     */
    public function __construct(
        private readonly string $key,
        private readonly array $parameters = [],
    ) {
        parent::__construct(self::englishMessage($this->key, $this->parameters));
    }

    public static function item(string $source, string $id): self
    {
        return new self('api.error.translations.unknown_item', ['source' => $source, 'id' => $id]);
    }

    public static function field(string $source, string $field): self
    {
        return new self('api.error.translations.unknown_field', ['source' => $source, 'field' => $field]);
    }

    public function translationKey(): string
    {
        return $this->key;
    }

    public function translationParameters(): array
    {
        return $this->parameters;
    }
}
