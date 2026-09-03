<?php

declare(strict_types=1);

namespace App\Modules\Auth\Data;

use App\Modules\Auth\Enums\MfaType;

/**
 * What a client needs to finish enrolling a method.
 *
 * Replaces the {secret, uri} array the contract used to return, which was shaped
 * around TOTP and meant nothing for a method that has neither. Each field is present
 * only for the methods it applies to, so adding a factor no longer requires bending
 * it into somebody else's vocabulary.
 */
final readonly class MfaEnrolment
{
    private function __construct(
        public MfaType $type,
        public ?string $secret = null,
        public ?string $uri = null,
        public ?string $destination = null,
    ) {}

    /**
     * A shared-secret method: the client stores the secret or scans the URI.
     */
    public static function forSecret(MfaType $type, string $secret, string $uri): self
    {
        return new self($type, $secret, $uri);
    }

    /**
     * A delivery method: the client is told, in masked form, where codes will go.
     */
    public static function forDelivery(MfaType $type, string $maskedDestination): self
    {
        return new self($type, destination: $maskedDestination);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_filter([
            'type' => $this->type->value,
            'secret' => $this->secret,
            'uri' => $this->uri,
            'destination' => $this->destination,
        ], static fn (mixed $value): bool => $value !== null);
    }
}
