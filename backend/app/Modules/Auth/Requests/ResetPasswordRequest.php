<?php

declare(strict_types=1);

namespace App\Modules\Auth\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class ResetPasswordRequest extends FormRequest
{
    private const FLOOR = 8;

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
            'email' => ['required', 'string', 'email', 'max:255'],
            'token' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string', 'min:'.$this->minimumPasswordLength(), 'max:1024', 'confirmed'],
        ];
    }

    /**
     * The same minimum every other place a password is set applies, from settings, and
     * never below the floor the setting itself declares.
     */
    private function minimumPasswordLength(): int
    {
        $configured = setting('auth.password_min_length', self::FLOOR);

        return is_int($configured) && $configured >= self::FLOOR ? $configured : self::FLOOR;
    }
}
