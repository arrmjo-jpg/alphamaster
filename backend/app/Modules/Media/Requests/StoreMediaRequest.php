<?php

declare(strict_types=1);

namespace App\Modules\Media\Requests;

use App\Modules\Media\Enums\MediaVisibility;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreMediaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Shape only. What the file actually is gets decided from its bytes by
     * UploadValidator, because every value here is supplied by the client: the mime
     * rule below checks a claim, not a fact, and is not relied upon for safety.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'max:102400'],
            'collection' => ['sometimes', 'string', 'max:60', 'regex:/^[a-z][a-z0-9_]*$/'],
            'visibility' => ['sometimes', 'string', Rule::in(MediaVisibility::values())],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'collection.regex' => 'A collection name must be a lowercase identifier.',
            'file.max' => 'The file exceeds the maximum upload size.',
        ];
    }
}
