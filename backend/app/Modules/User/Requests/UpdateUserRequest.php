<?php

declare(strict_types=1);

namespace App\Modules\User\Requests;

use App\Modules\User\Models\User;
use App\Modules\User\Rules\ReadablePhoneNumber;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateUserRequest extends FormRequest
{
    /**
     * Authorization is the route perimeter plus `users.update`.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Who the account is, and nothing about what it may do.
     *
     * Four fields are deliberately absent, each with its own reason:
     *
     *   * `password` — setting another person's password is a credential-reset
     *     primitive, and this platform has no password-reset flow to make it part of.
     *     An endpoint that let one administrator silently take over another
     *     administrator's sign-in is not a profile edit, and it is not added here as
     *     a side effect of one;
     *   * `account_type` — AccountTypeManager is the only sanctioned route across the
     *     administrative boundary, and promote/demote are where it happens;
     *   * `is_active` — a state with consequences (tokens revoked, sign-in refused)
     *     that belongs to its own operation rather than to a field somebody might set
     *     while renaming an account;
     *   * `roles` — `PUT /admin/users/{user}/roles` takes the whole set and requires
     *     `roles.update`, which is a different permission from this one.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        // The route binds the account before validation runs, so there is one here
        // whenever these rules are evaluated.
        /** @var User $account */
        $account = $this->route('user');

        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'email' => [
                'sometimes',
                'string',
                'email',
                'max:255',
                Rule::unique('users', 'email')->ignore($account->id),
            ],

            /**
             * The account's phone number, or null to remove it. Stored canonically;
             * the lookup hash is derived and is never accepted from outside.
             *
             * @var string|null
             */
            'phone' => ['sometimes', 'nullable', 'string', 'max:32', new ReadablePhoneNumber],

            /**
             * The language the platform addresses this account in. Constrained to the
             * languages table, so a preference can never name one the platform does
             * not serve.
             *
             * @var string|null
             */
            'preferred_locale' => [
                'sometimes',
                'nullable',
                'string',
                'max:10',
                Rule::exists('languages', 'code'),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'preferred_locale.exists' => __('validation.custom.preferred_locale.exists'),
        ];
    }
}
