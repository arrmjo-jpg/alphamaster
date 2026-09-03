<?php

declare(strict_types=1);

namespace App\Modules\Auth\Data;

use App\Modules\Auth\Enums\TokenAbility;
use App\Modules\User\Models\User;

/**
 * A freshly issued access token together with the identity it belongs to.
 *
 * Carrying both avoids re-reading the token back out of the database purely to
 * discover which ability it was granted.
 */
final readonly class AuthenticatedToken
{
    public function __construct(
        public User $user,
        public string $plainTextToken,
        public TokenAbility $ability,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'token' => $this->plainTextToken,
            'token_type' => 'Bearer',
            'abilities' => [$this->ability->value],
        ];
    }
}
