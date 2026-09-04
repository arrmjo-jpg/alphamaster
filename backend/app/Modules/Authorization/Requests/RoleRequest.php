<?php

declare(strict_types=1);

namespace App\Modules\Authorization\Requests;

use App\Modules\Authorization\Enums\AdminPermission;
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
        $roleId = $this->route('role')?->id;

        return [
            'name' => [
                'required', 'string', 'max:100',
                'regex:/^[a-z][a-z0-9_]*$/',
                Rule::unique('roles', 'name')->ignore($roleId),
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
            'name.regex' => __('validation.custom.name.regex'),
            'permissions.*.in' => __('validation.custom.permissions.*.in'),
        ];
    }
}
