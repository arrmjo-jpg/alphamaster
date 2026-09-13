<?php

declare(strict_types=1);

namespace App\Modules\Localization\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * What the workshop is asked for: one language, filtered and searched on the server,
 * a page at a time (ADR 0048 §4).
 *
 * The states are the ones the platform stores — a field has text or it does not, and
 * its suggestion is ready or failed — so a filter can never promise a distinction the
 * data cannot make.
 */
class WorkshopQueryRequest extends FormRequest
{
    public const STATES = ['all', 'missing', 'translated', 'needs_review', 'failed'];

    public const MAX_PER_PAGE = 100;

    public const DEFAULT_PER_PAGE = 25;

    public function authorize(): bool
    {
        // Per source, in the controller (ADR 0043 §3).
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // The language being written. Absent means the first language that is not
            // the default one; a code that is not a language is refused.
            'target' => ['sometimes', 'nullable', 'string', 'max:16'],
            'state' => ['sometimes', 'nullable', 'string', Rule::in(self::STATES)],
            'search' => ['sometimes', 'nullable', 'string', 'max:200'],
            'source' => ['sometimes', 'nullable', 'string', 'max:64'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:'.self::MAX_PER_PAGE],
        ];
    }
}
