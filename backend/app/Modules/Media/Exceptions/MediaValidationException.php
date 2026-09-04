<?php

declare(strict_types=1);

namespace App\Modules\Media\Exceptions;

use App\Modules\Core\Concerns\CarriesLocalizableMessage;
use App\Modules\Core\Contracts\LocalizableException;
use RuntimeException;

/**
 * Raised when an upload is rejected before it is stored.
 *
 * Carries a machine-readable reason so the API can report why without the
 * message being parsed. The reason is coarser than the message — several
 * distinct failures share `bad_filename` — so it identifies the category a
 * client branches on, and the translation key identifies what the person is
 * told. They are separate on purpose.
 */
class MediaValidationException extends RuntimeException implements LocalizableException
{
    use CarriesLocalizableMessage;

    /**
     * @param  array<string, mixed>  $parameters
     */
    public function __construct(
        public readonly string $reason,
        private readonly string $translationKey,
        private readonly array $parameters = []
    ) {
        parent::__construct(self::englishMessage($translationKey, $parameters));
    }

    public function translationKey(): string
    {
        return $this->translationKey;
    }

    public function translationParameters(): array
    {
        return $this->parameters;
    }
}
