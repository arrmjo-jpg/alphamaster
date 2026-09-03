<?php

declare(strict_types=1);

namespace App\Modules\Auth\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class MfaChallengeRequest extends FormRequest
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
            'mfa_token' => ['required', 'string', 'size:64'],
            // Wide enough for a 6-digit TOTP or a grouped recovery code.
            'code' => ['required', 'string', 'min:6', 'max:32'],
        ];
    }
}
