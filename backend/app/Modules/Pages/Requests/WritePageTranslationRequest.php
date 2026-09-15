<?php

declare(strict_types=1);

namespace App\Modules\Pages\Requests;

use App\Modules\Core\Seo\SeoFields;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * One language's text, address and SEO for a page (ADR 0055).
 *
 * Only the fields sent are changed; null or an empty string clears one. The language is in
 * the path, never taken from `X-Locale`.
 */
class WritePageTranslationRequest extends FormRequest
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
            'title' => ['sometimes', 'nullable', 'string', 'max:200'],
            /** Lower-case letters in any script, digits and single hyphens. Made from the title when absent. */
            'slug' => ['sometimes', 'nullable', 'string', 'max:190'],
            'summary' => ['sometimes', 'nullable', 'string', 'max:1000'],
            /** HTML. Sanitised on write: scripts, styles, event handlers and unsafe links are removed. */
            'body' => ['sometimes', 'nullable', 'string', 'max:200000'],
            // This language's search and sharing fields, validated by Core's rules. Omit to
            // leave them as they are.
            ...SeoFields::rules(),
        ];
    }

    /**
     * @return array<string, string|null>
     */
    public function content(): array
    {
        $content = [];

        foreach (['title', 'slug', 'summary', 'body'] as $field) {
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
