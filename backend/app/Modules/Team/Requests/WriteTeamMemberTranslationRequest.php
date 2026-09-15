<?php

declare(strict_types=1);

namespace App\Modules\Team\Requests;

use App\Modules\Core\Seo\SeoFields;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

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
            // This language's search and sharing fields, validated by Core's rules.
            ...SeoFields::rules(),
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
