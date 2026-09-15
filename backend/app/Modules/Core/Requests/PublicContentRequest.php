<?php

declare(strict_types=1);

namespace App\Modules\Core\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A request for public content, which always names its language (ADR 0055 §5).
 *
 * The content language is part of the address and nowhere else. `X-Locale`,
 * `Accept-Language` and the account's preference still choose the language of messages
 * and labels; they never choose which content is returned, so the same address always
 * returns the same content and an edge can store it.
 */
class PublicContentRequest extends FormRequest
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
             * The language of the content, as a code from Language Management. Required: nothing is negotiated.
             */
            'locale' => ['required', 'string', 'max:10', 'regex:/^[a-z]{2,3}(-[a-z0-9]{2,8})?$/i'],
        ];
    }

    public function contentLocale(): string
    {
        return strtolower((string) $this->validated('locale'));
    }
}
