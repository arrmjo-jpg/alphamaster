<?php

declare(strict_types=1);

namespace App\Modules\Settings\Exceptions;

use RuntimeException;

/**
 * A value did not satisfy the rules its definition declares (ADR 0018).
 *
 * Distinct from the type refusal `Setting::serializeValue` raises. That one says the
 * value cannot be represented at all; this one says it can be represented and is not
 * allowed — an opacity of 400, an address that is not an address, a media id pointing at
 * something that has been deleted.
 *
 * It names the setting and carries the messages, because an operator writing twenty
 * settings at once needs to know which of them was refused and why.
 */
class SettingValueRejectedException extends RuntimeException
{
    /**
     * @param  array<int, string>  $messages
     */
    public function __construct(
        private readonly string $reference,
        private readonly array $messages,
    ) {
        parent::__construct("The value for [{$reference}] is not allowed by its declaration.");
    }

    public function reference(): string
    {
        return $this->reference;
    }

    /**
     * @return array<string, mixed>
     */
    public function details(): array
    {
        return ['setting' => $this->reference, 'messages' => $this->messages];
    }
}
