<?php

declare(strict_types=1);

namespace App\Modules\Auth\Requests;

use App\Modules\User\Rules\ReadablePhoneNumber;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class SendPhoneSignInCodeRequest extends FormRequest
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
            /**
             * The number to sign in or register with, with its country code.
             *
             * @example +962790000000
             */
            'phone' => ['required', 'string', 'max:32', new ReadablePhoneNumber],
        ];
    }
}
