<?php

declare(strict_types=1);

namespace App\Modules\Integration\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * What a connection test asks about.
 *
 * With no body, the default provider is tested as saved. Naming a provider tests that
 * one; adding a key or a model tests the form as it stands, before anything is saved —
 * the key is used for this one call and never stored.
 */
class AiCheckRequest extends FormRequest
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
        return [
            /** The provider to test, by driver name. Absent means the default provider. */
            'provider' => ['sometimes', 'nullable', 'string', 'max:50'],
            /** A key to test with instead of the stored one. Never stored. */
            'api_key' => ['sometimes', 'nullable', 'string', 'max:500'],
            /** A model to test with instead of the saved one. */
            'model' => ['sometimes', 'nullable', 'string', 'max:200', 'regex:'.SaveAiProviderRequest::MODEL_PATTERN],
        ];
    }
}
