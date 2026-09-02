<?php

declare(strict_types=1);

namespace App\Modules\Localization\Requests;

use App\Modules\Localization\Enums\LanguageDirection;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateLanguageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $languageId = $this->route('id') ?? $this->route('language');

        return [
            'code' => ['sometimes', 'string', 'min:2', 'max:10', Rule::unique('languages', 'code')->ignore($languageId)],
            'name' => ['sometimes', 'string', 'max:100'],
            'native_name' => ['sometimes', 'string', 'max:100'],
            'direction' => ['sometimes', Rule::enum(LanguageDirection::class)],
            'is_active' => ['sometimes', 'boolean'],
            'is_default' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
        ];
    }
}
