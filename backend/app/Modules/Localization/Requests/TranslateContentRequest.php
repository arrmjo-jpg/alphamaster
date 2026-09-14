<?php

declare(strict_types=1);

namespace App\Modules\Localization\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Translate with AI: one item, one source, or everything missing in a language (ADR 0056).
 *
 * There is no field in this request, and that is the contract: which fields are translated is
 * read from the item's metadata, never chosen by the operator.
 */
class TranslateContentRequest extends FormRequest
{
    /**
     * Authorization is the route's and the controller's: the perimeter and `ai.use` are
     * middleware, and which content may be translated depends on the source.
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
            // The language being written. Checked against the platform's languages by the
            // controller; the shape is all that is asserted here.
            'locale' => ['required', 'string', 'max:16'],

            // Narrow to one body of content, or one item within it. Absent means everything
            // the caller may write.
            'source' => ['sometimes', 'nullable', 'string', 'max:64'],
            'item' => ['sometimes', 'nullable', 'string', 'max:200'],

            // Off by default: translating a language means filling its gaps. Paying a vendor
            // to replace text a person already wrote is a request an operator makes on purpose.
            'include_translated' => ['sometimes', 'boolean'],
        ];
    }
}
