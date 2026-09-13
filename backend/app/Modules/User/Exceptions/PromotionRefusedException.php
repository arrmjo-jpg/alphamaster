<?php

declare(strict_types=1);

namespace App\Modules\User\Exceptions;

use App\Modules\Core\Concerns\CarriesLocalizableMessage;
use App\Modules\Core\Contracts\LocalizableException;
use RuntimeException;

/**
 * Raised when an account cannot be promoted because a social identity is linked to it
 * (ADR 0050 §6).
 *
 * A refusal rather than a quiet return, because `promote()` already returns an account
 * unchanged when it is an administrator already, and a caller must be able to tell
 * "nothing to do" from "not allowed".
 *
 * Nothing is unlinked or removed to make promotion possible. Unlinking is the account
 * holder's act; once every identity is unlinked, promotion follows the ordinary rules.
 */
class PromotionRefusedException extends RuntimeException implements LocalizableException
{
    use CarriesLocalizableMessage;

    public function __construct(
        public readonly string $userId,
        public readonly int $linkedIdentities,
    ) {
        parent::__construct(self::englishMessage($this->translationKey()));
    }

    public function translationKey(): string
    {
        return 'api.error.user.promotion_refused_social_identity';
    }
}
