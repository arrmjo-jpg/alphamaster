<?php

declare(strict_types=1);

namespace App\Modules\Auth\Requests;

use App\Modules\User\Rules\ReadablePhoneNumber;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PhoneSignInRequest extends FormRequest
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
            'phone' => ['required', 'string', 'max:32', new ReadablePhoneNumber],
            'code' => ['required', 'string', 'regex:/^\d{4,8}$/'],

            /**
             * Needed only when the number has no account yet; the account is created with it.
             */
            'name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'preferred_locale' => ['sometimes', 'nullable', 'string', 'max:10', Rule::exists('languages', 'code')],
        ];
    }
}
