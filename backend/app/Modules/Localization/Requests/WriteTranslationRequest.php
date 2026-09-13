<?php

declare(strict_types=1);

namespace App\Modules\Localization\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * One item's translated fields, in one language.
 */
class WriteTranslationRequest extends FormRequest
{
    /**
     * Authorization is the route's and the controller's: the perimeter is middleware,
     * and which permission applies depends on which source is being written, which
     * a request cannot know.
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
            // shape is all that is asserted here — a locale that exists is a question
            // about data, not about the payload.
            'locale' => ['required', 'string', 'max:16'],

            // Field names are the source's, so they cannot be enumerated here. An
            // unknown one is refused by the source rather than accepted and dropped.
            /** @var array<string, string|null> */
            'values' => ['required', 'array', 'min:1'],

            // Null is meaningful and allowed: it is how an editor takes a translation
            // back and lets the language fall back to the platform's own wording.
            // `ConvertEmptyStringsToNull` runs on every request here, so an emptied
            // field arrives as null however a client chose to send it — a rule
            // insisting on a string would refuse the one thing clearing looks like.
            'values.*' => ['present', 'nullable', 'string', 'max:20000'],
        ];
    }
}
