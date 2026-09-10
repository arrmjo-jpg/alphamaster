<?php

declare(strict_types=1);

namespace App\Modules\Localization\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Accept a proposed translation, as the person accepting it wants it.
 */
class AcceptSuggestionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            // The text comes from the request rather than from the stored suggestion,
            // because the whole point is that a person may have edited it before
            // deciding. Accepting something the platform did not propose is the normal
            // case, not an edge one.
            'text' => ['required', 'string', 'max:20000'],
        ];
    }
}
