<?php

declare(strict_types=1);

namespace App\Modules\Pages\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Creating a page, or changing what is the same in every language.
 *
 * The text arrives one language at a time, through the translation endpoint.
 */
class StorePageRequest extends FormRequest
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
            /** Where the page sits in lists, ascending. */
            'sort_order' => ['sometimes', 'integer', 'between:0,1000000'],
        ];
    }
}
