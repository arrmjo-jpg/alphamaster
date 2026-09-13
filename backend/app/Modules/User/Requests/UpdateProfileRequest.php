<?php

declare(strict_types=1);

namespace App\Modules\User\Requests;

use App\Modules\User\Models\User;
use App\Modules\User\Rules\ReadablePhoneNumber;
use App\Modules\User\Rules\UnusedPhoneNumber;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('email'))) {
            $this->merge(['email' => mb_strtolower(trim($this->input('email')))]);
        }

        if (is_string($this->input('country_code'))) {
            $this->merge(['country_code' => mb_strtoupper(trim($this->input('country_code')))]);
        }
    }

    /**
     * Only what the account holder decides about themselves. Account type, activation
     * and roles are not here, and an administrator's address is refused by the
     * controller rather than accepted here.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        /** @var User|null $account */
        $account = $this->user();

        return [
            'name' => ['sometimes', 'string', 'filled', 'max:255'],
            'email' => ['sometimes', 'string', 'email', 'max:255', Rule::unique('users', 'email')->ignore($account?->id)],

            /**
             * A new number is stored unverified. Null removes it.
             *
             * @var string|null
             */
            'phone' => ['sometimes', 'nullable', 'string', 'max:32', new ReadablePhoneNumber, new UnusedPhoneNumber($account?->id)],
            'preferred_locale' => ['sometimes', 'nullable', 'string', 'max:10', Rule::exists('languages', 'code')],
            'bio' => ['sometimes', 'nullable', 'string', 'max:500'],

            /**
             * ISO 3166-1 alpha-2, e.g. JO.
             *
             * @var string|null
             */
            'country_code' => ['sometimes', 'nullable', 'string', 'regex:/^[A-Z]{2}$/'],
            'region' => ['sometimes', 'nullable', 'string', 'max:100'],
            'city' => ['sometimes', 'nullable', 'string', 'max:100'],

            /**
             * Both or neither.
             *
             * @var float|null
             */
            // Not `sometimes`: a latitude sent alone must make the missing longitude an
            // error, and a rule that skips an absent field never reports it.
            'latitude' => ['nullable', 'numeric', 'between:-90,90', 'required_with:longitude'],

            /**
             * Both or neither.
             *
             * @var float|null
             */
            'longitude' => ['nullable', 'numeric', 'between:-180,180', 'required_with:latitude'],
        ];
    }
}
