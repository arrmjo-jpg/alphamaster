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
     * `captcha_token` is optional even when the platform is asking for one, for the
     * same reason. `required_if` against the setting would answer 422 for a missing
     * token and 401 for a rejected one, and the difference between those two
     * responses is exactly the signal this endpoint refuses to give. An absent token
     * is a captcha that did not pass, handled where every other refusal is handled.
     * Its bound is generous because a reCAPTCHA response runs to a couple of thousand
     * characters and has grown between versions.
     *
     * The rationale lives here rather than beside the rule because Scramble reads an
     * inline comment as the field's public description, and internal reasoning is not
     * what a client should be handed.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'identifier' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string', 'max:1024'],
            // The captcha response, when the platform is asking for one.
            'captcha_token' => ['sometimes', 'nullable', 'string', 'max:5000'],
        ];
    }
}
