<?php

declare(strict_types=1);

namespace App\Modules\Auth\Requests;

use App\Modules\User\Rules\ReadablePhoneNumber;
use App\Modules\User\Rules\UnusedPhoneNumber;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * An address is compared in the case it is stored in.
     */
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('email'))) {
            $this->merge(['email' => mb_strtolower(trim($this->input('email')))]);
        }
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users', 'email')],
            'password' => ['required', 'string', 'min:'.$this->minimumPasswordLength(), 'max:1024', 'confirmed'],

            /**
             * Stored unverified; confirm it through phone verification.
             *
             * @var string|null
             */
            'phone' => ['sometimes', 'nullable', 'string', 'max:32', new ReadablePhoneNumber, new UnusedPhoneNumber],
            'preferred_locale' => ['sometimes', 'nullable', 'string', 'max:10', Rule::exists('languages', 'code')],
        ];
    }

    private function minimumPasswordLength(): int
    {
        $configured = setting('auth.password_min_length', 8);

        return is_int($configured) && $configured >= 8 ? $configured : 8;
    }
}
