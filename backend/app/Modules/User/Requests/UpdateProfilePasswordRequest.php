<?php

declare(strict_types=1);

namespace App\Modules\User\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateProfilePasswordRequest extends FormRequest
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
            /**
             * Required when the account already has a password.
             *
             * @var string|null
             */
            'current_password' => ['sometimes', 'nullable', 'string', 'max:1024'],
            'password' => ['required', 'string', 'min:'.$this->minimumPasswordLength(), 'max:1024', 'confirmed'],
        ];
    }

    private function minimumPasswordLength(): int
    {
        $configured = setting('auth.password_min_length', 8);

        return is_int($configured) && $configured >= 8 ? $configured : 8;
    }
}
