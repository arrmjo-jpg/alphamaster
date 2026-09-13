<?php

declare(strict_types=1);

namespace App\Modules\Auth\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class VerifyPhoneRequest extends FormRequest
{
    /**
     * Authorization is the perimeter: the route acts on the caller and takes no
     * account identifier, so there is nobody else's number to reach.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * The code, and nothing else.
     *
     * Bounded rather than exact, because the configured length can change and a
     * request validated against yesterday's length would refuse today's code before
     * the verifier ever saw it. What the code actually has to match is the hash, and
     * that comparison is the verifier's.
     *
     * The reasoning sits here rather than beside the rule, because Scramble publishes
     * an inline comment above a rules-array key as that field's public description.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            /**
             * The code delivered by SMS.
             *
             * @var string
             */
            'code' => ['required', 'string', 'min:4', 'max:8'],
        ];
    }
}
