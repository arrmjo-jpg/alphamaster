<?php

declare(strict_types=1);

namespace App\Modules\Core\Translation;

use App\Modules\Core\Concerns\CarriesLocalizableMessage;
use App\Modules\Core\Contracts\LocalizableException;
use RuntimeException;

/**
 * Raised when a source will not accept a write for a reason of its own.
 *
 * Distinct from `UnknownTranslationTargetException`, which is about addressing
 * something that is not there. This is about a write that names real content and
 * would leave it in a state the owning module does not allow — a notification template
 * with a subject in one language and no body, say.
 *
 * The reason is a translation key the source supplies, because the source is the only
 * thing that knows it. The workshop reports it and does not interpret it.
 */
class TranslationRefusedException extends RuntimeException implements LocalizableException
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

    /**
     * @param  array<string, string|int>  $parameters
     */
    public static function because(string $key, array $parameters = []): self
    {
        return new self($key, $parameters);
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
