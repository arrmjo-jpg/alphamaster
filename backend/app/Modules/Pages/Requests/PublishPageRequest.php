<?php

declare(strict_types=1);

namespace App\Modules\Pages\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class PublishPageRequest extends FormRequest
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
            /** When the page becomes public. Omitted, it keeps its earlier publication time, or now. */
            'published_at' => ['sometimes', 'nullable', 'date'],
        ];
    }
}
