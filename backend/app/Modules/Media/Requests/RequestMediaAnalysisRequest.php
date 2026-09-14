<?php

declare(strict_types=1);

namespace App\Modules\Media\Requests;

use App\Modules\Core\MediaAnalysis\MediaAnalysisType;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RequestMediaAnalysisRequest extends FormRequest
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
             * What to look for, from the platform's vocabulary. Types the configured analyzer does not support are reported, not scored.
             *
             * @var list<string>
             */
            'types' => ['required', 'array', 'min:1', 'max:'.count(MediaAnalysisType::cases())],
            'types.*' => ['required', 'string', 'distinct', Rule::enum(MediaAnalysisType::class)],

            /** Run again even when an equivalent analysis exists; the new one supersedes the current one when it finishes. */
            'reanalyze' => ['sometimes', 'boolean'],
        ];
    }
}
