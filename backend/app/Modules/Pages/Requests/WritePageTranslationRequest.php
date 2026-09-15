<?php

declare(strict_types=1);

namespace App\Modules\Pages\Requests;

use App\Modules\Core\Seo\SeoFields;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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
            /** This language's search and sharing fields. Omit to leave them as they are. */
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
