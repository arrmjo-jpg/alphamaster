<?php

declare(strict_types=1);

namespace App\Modules\Authorization\Requests;

use App\Modules\Authorization\Enums\AdminPermission;
use App\Modules\Authorization\Services\RoleIdentifier;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RoleRequest extends FormRequest
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
            // `label` is what an administrator types, and it is named for what it
            // is: the response's `name` is the machine identifier, so reusing that
            // word here would give one field two meanings across the same
            // resource. The identifier is derived from this server-side and is
            // immutable afterwards (ADR 0029 item 12), so this carries no
            // identifier grammar and no uniqueness rule — two roles may read the
            // same in a list while remaining distinct underneath.
            'label' => [
                'required', 'string', 'max:100',
                function (string $attribute, mixed $value, Closure $fail): void {
                    if (! is_string($value) || app(RoleIdentifier::class)->fromLabel($value) === null) {
                        $fail(__('validation.custom.label.unusable'));
                    }
                },
            ],
            'permissions' => ['present', 'array', 'max:100'],
            // Only catalogued permissions may be attached, so a role cannot be given
            // a permission string the platform does not actually enforce anywhere.
            'permissions.*' => ['required', 'string', Rule::in(AdminPermission::values())],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'permissions.*.in' => __('validation.custom.permissions.*.in'),
        ];
    }
}
