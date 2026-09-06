<?php

declare(strict_types=1);

namespace App\Modules\Core\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class ExportConfigurationRequest extends FormRequest
{
    /**
     * Authorization is the route perimeter plus `settings.backup.manage`.
     */
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
            // Defaults to false when absent. Carrying ciphertext has to be asked for:
            // the safe artefact is the one that is worth less if it leaks, and an
            // operator who wanted the other one knows they did.
            'include_secrets' => ['sometimes', 'boolean'],
        ];
    }
}
