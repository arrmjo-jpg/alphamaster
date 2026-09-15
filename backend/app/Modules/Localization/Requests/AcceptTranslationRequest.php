<?php

declare(strict_types=1);

namespace App\Modules\Localization\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Accept one item's translation, as the reviewer wants it (ADR 0056).
 */
class AcceptTranslationRequest extends FormRequest
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
            // Only the fields the reviewer changed, keyed by field name. Every field not named
            // is accepted as it was generated. Null clears an optional field.
            'values' => ['sometimes', 'array'],
            'values.*' => ['nullable', 'string', 'max:200000'],
        ];
    }
}
