<?php

declare(strict_types=1);

namespace App\Modules\Settings\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class RotateSecretRequest extends FormRequest
{
    /**
     * Maximum stored size, in bytes, of a credential.
     *
     * The same bound the ordinary settings batch applies to a single value. A
     * credential far past this is not a credential.
     */
    private const MAX_BYTES = 60000;

    /**
     * Determine if the user is authorized to make this request.
     *
     * Authorization is enforced by the route's admin perimeter and then by
     * `settings.secrets.manage`, which is what every write to a secret requires.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * The rules are also chosen so that none of their messages interpolates the value
     * itself: `required`, `string`, `min` and `max` each report the constraint and never
     * the input, so a rejected credential is not echoed back in the response body.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            // Required and non-empty. Rotation replaces a credential with another one;
            // emptying a secret is a different operation with a different audit action,
            // and ADR 0038 is explicit that a failed rotation must never leave the
            // field empty — the surest way to honour that is to have no path here that
            // could empty it.
            'credential' => ['required', 'string', 'min:1', 'max:'.self::MAX_BYTES],
        ];
    }
}
