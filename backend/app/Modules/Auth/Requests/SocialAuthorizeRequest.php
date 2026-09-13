<?php

declare(strict_types=1);

namespace App\Modules\Auth\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class SocialAuthorizeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * The client's side of PKCE, and where the provider should send it back.
     *
     * Only the challenge is accepted here, never the verifier: a challenge is the SHA-256
     * of the verifier, base64url without padding, which is always 43 characters. S256 is
     * the only method, because a plain challenge is the verifier itself.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            /**
             * Must be one of the redirect URIs an operator allowed, exactly.
             */
            'redirect_uri' => ['required', 'string', 'max:2048'],
            'code_challenge' => ['required', 'string', 'regex:/^[A-Za-z0-9_-]{43}$/'],
            'code_challenge_method' => ['required', 'string', 'in:S256'],
        ];
    }
}
