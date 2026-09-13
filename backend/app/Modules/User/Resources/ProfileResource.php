<?php

declare(strict_types=1);

namespace App\Modules\User\Resources;

use App\Modules\User\Models\User;
use App\Modules\User\Models\UserProfileLink;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The signed-in account's own profile (ADR 0051 §4).
 *
 * Everything here is the account's own and is shown back to it in full. Nothing that
 * is a credential or a lookup value is: not the password, not the phone digest — only
 * whether a password exists, so a client can offer "set" rather than "change".
 *
 * @property-read User $resource
 */
class ProfileResource extends JsonResource
{
    public function __construct(User $resource, private readonly ?string $avatarUrl)
    {
        parent::__construct($resource);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $user = $this->resource;

        return [
            'id' => $user->id,
            'account_type' => $user->account_type->value,
            'name' => $user->name,
            'email' => $user->email,
            'email_verified' => $user->email !== null && $user->hasVerifiedEmail(),
            'email_verified_at' => $user->email_verified_at?->toIso8601String(),
            'phone' => $user->phone,
            'phone_verified' => $user->phone_verified_at !== null,
            'phone_verified_at' => $user->phone_verified_at?->toIso8601String(),
            'has_password' => $user->password !== null,
            'preferred_locale' => $user->preferred_locale,
            'bio' => $user->bio,
            'avatar_url' => $this->avatarUrl,
            'location' => [
                'country_code' => $user->country_code,
                'region' => $user->region,
                'city' => $user->city,
                'latitude' => $user->latitude === null ? null : (float) $user->latitude,
                'longitude' => $user->longitude === null ? null : (float) $user->longitude,
                'updated_at' => $user->location_updated_at?->toIso8601String(),
            ],
            'links' => $user->profileLinks
                ->map(static fn (UserProfileLink $link): array => [
                    'platform' => $link->platform->value,
                    'url' => $link->url,
                    'position' => $link->position,
                ])
                ->values()
                ->all(),
        ];
    }
}
