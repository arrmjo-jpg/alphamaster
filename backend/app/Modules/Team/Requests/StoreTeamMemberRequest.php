<?php

declare(strict_types=1);

namespace App\Modules\Team\Requests;

use App\Modules\Team\Services\TeamMemberContent;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Creating a team member, or changing what is the same in every language.
 *
 * Profiles arrive one language at a time, through the translation endpoint.
 */
class StoreTeamMemberRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $rules = [
            /** Shown publicly. Needs a complete default-language profile. New members start inactive. */
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'between:0,1000000'],
            /** A public image from the media library. */
            'avatar_media_id' => ['sometimes', 'nullable', 'string', 'ulid'],
            /** Addresses keyed by network: website, x, facebook, instagram, linkedin, youtube, tiktok, github, telegram. */
            'social_links' => ['sometimes', 'nullable', 'array:'.implode(',', TeamMemberContent::SOCIAL_NETWORKS)],
        ];

        foreach (TeamMemberContent::SOCIAL_NETWORKS as $network) {
            $rules['social_links.'.$network] = ['sometimes', 'nullable', 'string', 'max:2048', 'url:http,https'];
        }

        return $rules;
    }

    /**
     * @return array<string, mixed>
     */
    public function sharedChanges(): array
    {
        return array_intersect_key($this->validated(), array_flip(['is_active', 'sort_order', 'avatar_media_id', 'social_links']));
    }
}
