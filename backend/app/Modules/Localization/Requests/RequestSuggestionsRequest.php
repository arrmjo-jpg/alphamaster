<?php

declare(strict_types=1);

namespace App\Modules\Localization\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Ask for translations of what is missing in one language.
 */
class RequestSuggestionsRequest extends FormRequest
{
    /**
     * Authorization is the route's and the controller's: the perimeter and `ai.use`
     * are middleware, and which content may be proposed for depends on the source,
     * which a request cannot know.
     */
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
            // Checked against the platform's active languages by the controller. The
            // shape is all that is asserted here.
            'locale' => ['required', 'string', 'max:16'],

            // Narrow to one body of content, or one item within it. Absent means
            // everything the caller may write.
            'source' => ['sometimes', 'nullable', 'string', 'max:64'],
            'item' => ['sometimes', 'nullable', 'string', 'max:200'],

            // Off by default, and deliberately: asking for a language means filling
            // the gaps. Paying a vendor to replace text a person already wrote is a
            // request an operator makes on purpose.
            'include_translated' => ['sometimes', 'boolean'],
        ];
    }
}
