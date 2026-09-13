<?php

declare(strict_types=1);

namespace App\Modules\Auth\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class SocialCallbackRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * What the provider sent the client back with, and the verifier only the client holds.
     *
     * Nothing here names an account, an identity or a redirect URI: those come from the
     * state the platform stored and from the token the provider signs.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:4096'],
            'state' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/'],
            // RFC 7636: 43 to 128 unreserved characters.
            'code_verifier' => ['required', 'string', 'regex:/^[A-Za-z0-9\-._~]{43,128}$/'],
        ];
    }
}
