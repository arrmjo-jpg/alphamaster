<?php

declare(strict_types=1);

namespace App\Modules\Settings\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class RollbackGroupSettingsRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * Authorization is enforced by the route's admin perimeter
     * (auth:sanctum + ability:admin:access + active + admin) and then by the
     * `settings.rollback` permission on the route itself. Which settings the caller
     * may actually roll back is decided against the computed plan, because the keys a
     * rollback touches are derived from history rather than submitted here.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * The target is a revision identifier and nothing else.
     *
     * A ULID by shape, so a malformed target is refused before it reaches a query.
     * Whether it exists, and whether it belongs to the group in the path, are questions
     * for the service — answered together, so that holding rollback on one group cannot
     * be used to discover which revision identifiers exist in another.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'revision_id' => ['required', 'string', 'ulid'],
        ];
    }
}
