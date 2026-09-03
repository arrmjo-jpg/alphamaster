<?php

declare(strict_types=1);

namespace App\Modules\User\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class SyncUserRolesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Only roles may be submitted here. account_type is deliberately absent, and is
     * not mass assignable in any case, so this endpoint cannot move an account across
     * the administrative boundary.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'roles' => ['present', 'array', 'max:20'],
            'roles.*' => ['required', 'string', 'exists:roles,name'],
        ];
    }
}
