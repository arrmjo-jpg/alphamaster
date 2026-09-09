<?php

declare(strict_types=1);

namespace App\Modules\User\Requests;

use App\Modules\User\Rules\ReadablePhoneNumber;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreUserRequest extends FormRequest
{
    /**
     * Authorization is the route perimeter plus `users.create`.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * What an account is created with, and nothing more.
     *
     * `account_type` is absent, and its absence is the point: it is excluded from mass
     * assignment and AccountTypeManager is documented as the only sanctioned way to
     * move an account across the administrative boundary. A create endpoint that
     * accepted it would be a second route across that boundary, reachable with
     * `users.create` rather than the `users.update` that promotion requires.
     *
     * `roles` is absent for the same reason at one remove: admin RBAC refuses an
     * account that is not an administrator, so a role submitted here could not be
     * granted, and accepting it would be accepting a field that does nothing.
     *
     * The password minimum is read from `auth.password_min_length` rather than fixed
     * here, so the policy an operator configured is the policy that applies. It is
     * confirmed because a mistyped password on an account somebody else will use
     * cannot be discovered by the person who typed it.
     *
     * The reasoning above sits in this docblock rather than beside the rules, because
     * Scramble publishes an inline comment above a rule as that field's public
     * description — a trap this project has been caught by before, and one that would
     * put internal rationale into the contract.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users', 'email')],

            /**
             * The account's phone number. Stored canonically; the lookup hash is
             * derived from it and is never accepted from outside.
             *
             * @var string|null
             */
            'phone' => ['sometimes', 'nullable', 'string', 'max:32', new ReadablePhoneNumber],

            /**
             * The account's first password. Must be confirmed, and must meet the
             * minimum length this platform is configured to require.
             *
             * @var string
             */
            'password' => ['required', 'string', 'min:'.$this->minimumPasswordLength(), 'confirmed'],

            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'password.min' => __('validation.custom.password.min', [
                'minimum' => $this->minimumPasswordLength(),
            ]),
        ];
    }

    /**
     * The configured minimum, defended against a setting that is absent or nonsense.
     *
     * The same reading `CreateFirstAdministratorCommand` does, and for the same
     * reason: a platform whose password policy lives in a setting should apply it
     * everywhere a password is accepted, not only where somebody remembered.
     */
    private function minimumPasswordLength(): int
    {
        $configured = setting('auth.password_min_length', 8);

        return is_int($configured) && $configured > 0 ? $configured : 8;
    }
}
