<?php

declare(strict_types=1);

namespace App\Modules\Team\Requests;

use App\Modules\Core\Seo\SeoFields;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * One language's profile and SEO for a team member (ADR 0055). The language is in the path.
 */
class WriteTeamMemberTranslationRequest extends FormRequest
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
        return [
            'name' => ['sometimes', 'nullable', 'string', 'max:150'],
            'position' => ['sometimes', 'nullable', 'string', 'max:150'],
            /** HTML. Sanitised on write. */
            'bio' => ['sometimes', 'nullable', 'string', 'max:50000'],
            /** Lower-case letters in any script, digits and single hyphens. Made from the name when absent. */
            'slug' => ['sometimes', 'nullable', 'string', 'max:190'],
            'seo' => ['sometimes', 'nullable', 'array'],
            'seo.title' => ['sometimes', 'nullable', 'string', 'max:255'],
            'seo.description' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'seo.robots' => ['sometimes', 'nullable', 'string', Rule::in(SeoFields::ROBOTS)],
            'seo.canonical_url' => ['sometimes', 'nullable', 'string', 'max:2048', 'url:http,https'],
            'seo.og_title' => ['sometimes', 'nullable', 'string', 'max:255'],
            'seo.og_description' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'seo.og_media_id' => ['sometimes', 'nullable', 'string', 'ulid'],
        ];
    }

    /**
     * @return array<string, string|null>
     */
    public function content(): array
    {
        $content = [];

        foreach (['name', 'position', 'bio', 'slug'] as $field) {
            if ($this->has($field)) {
                $value = $this->validated($field);
                $content[$field] = is_string($value) ? $value : null;
            }
        }

        return $content;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function seo(): ?array
    {
        if (! $this->has('seo')) {
            return null;
        }

        $seo = $this->validated('seo');

        return is_array($seo) ? $seo : [];
    }
}
