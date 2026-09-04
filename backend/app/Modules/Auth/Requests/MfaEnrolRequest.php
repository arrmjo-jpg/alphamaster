<?php

declare(strict_types=1);

namespace App\Modules\Auth\Requests;

use App\Modules\Auth\Enums\MfaType;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class MfaEnrolRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * The phone number is required only for a delivery-based method. Whether the
     * caller is permitted that method at all is the manager's decision, not this
     * layer's: validation shapes input, the policy decides who may use it.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'type' => ['sometimes', 'string', Rule::in(MfaType::values())],
            'phone' => [
                Rule::requiredIf(fn (): bool => $this->input('type') === MfaType::SMS_OTP->value),
                'nullable',
                'string',
                // E.164: a leading + and up to fifteen digits.
                'regex:/^\+[1-9]\d{6,14}$/',
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'phone.regex' => __('validation.custom.phone.regex'),
        ];
    }
}
