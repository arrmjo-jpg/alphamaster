<?php

declare(strict_types=1);

namespace App\Modules\Auth\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * One field for both kinds of identifier, and no rule that inspects its shape.
     *
     * There is deliberately no `email` rule and no phone format rule here. Either
     * would answer 422 for a well-formed value of the other kind, and — worse — a
     * shape-specific message tells an unauthenticated caller that its input was
     * recognised, which is the distinction this endpoint spends the rest of its
     * effort not making. An identifier that names no account is refused with the
     * same INVALID_CREDENTIALS as one whose password was wrong.
     *
     * `max:255` is a bound on the payload rather than a statement about form. It
     * matches the email column's width, and every E.164 number fits inside it many
     * times over.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'identifier' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string', 'max:1024'],
        ];
    }
}
