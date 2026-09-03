<?php

declare(strict_types=1);

namespace App\Modules\Notification\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateNotificationTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * The type is absent on purpose: it identifies which notification a template
     * renders, not how it reads, and changing it would silently repoint one
     * notification's wording at another.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'is_active' => ['sometimes', 'boolean'],
            'translations' => ['sometimes', 'array', 'max:50'],
            'translations.*.locale' => ['required', 'string', 'max:10', 'exists:languages,code'],
            'translations.*.subject' => ['required', 'string', 'max:255'],
            'translations.*.body' => ['required', 'string', 'max:20000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'translations.*.locale.exists' => 'Templates may only be translated into a language the platform has configured.',
        ];
    }
}
