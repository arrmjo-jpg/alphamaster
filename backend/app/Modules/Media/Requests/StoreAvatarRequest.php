<?php

declare(strict_types=1);

namespace App\Modules\Media\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreAvatarRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * A still image a browser can show everywhere, at most 5 MB. The media validator
     * still inspects the bytes rather than trusting the name or the declared type.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'image', 'mimetypes:image/jpeg,image/png,image/webp', 'max:5120'],
        ];
    }
}
