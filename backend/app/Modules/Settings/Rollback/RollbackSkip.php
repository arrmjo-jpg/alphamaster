<?php

declare(strict_types=1);

namespace App\Modules\Settings\Rollback;

/**
 * One setting a rollback deliberately did not restore, and why (ADR 0040).
 *
 * Carries no value — not the one it declined to write, not the one it left in place.
 * A skip is reported to an operator so they know what to do next, and the reason is
 * enough for that; including the value would put history's contents into a payload
 * that exists to describe an exception.
 */
final class RollbackSkip
{
    public function __construct(
        public readonly string $key,
        public readonly ?string $locale,
        public readonly RollbackSkipReason $reason,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'locale' => $this->locale,
            'reason' => $this->reason->value,
            // Beside the machine-readable reason, never instead of it (ADR 0031).
            'reason_label' => __($this->reason->translationKey()),
        ];
    }
}
