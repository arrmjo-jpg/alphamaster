<?php

declare(strict_types=1);

namespace App\Modules\Integration\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateIntegrationProviderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * capability and driver are absent on purpose: they identify which integration a
     * row is, not how it is configured, and changing them would silently repoint a
     * provider at a different vendor's contract.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'label' => ['sometimes', 'string', 'max:100'],
            'settings' => ['sometimes', 'nullable', 'array'],
            'settings.*' => ['nullable', 'string', 'max:500'],
            'credentials' => ['sometimes', 'nullable', 'array'],
            'credentials.*' => ['required', 'string', 'max:500'],
            'is_active' => ['sometimes', 'boolean'],
            'priority' => ['sometimes', 'integer', 'min:0', 'max:1000'],
        ];
    }
}
