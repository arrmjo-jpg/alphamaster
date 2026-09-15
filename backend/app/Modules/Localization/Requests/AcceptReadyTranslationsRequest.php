<?php

declare(strict_types=1);

namespace App\Modules\Localization\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Accept every item ready for review in one language, or in one source of it (ADR 0056).
 */
class AcceptReadyTranslationsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'locale' => ['required', 'string', 'max:16'],
            'source' => ['sometimes', 'nullable', 'string', 'max:64'],
        ];
    }
}
