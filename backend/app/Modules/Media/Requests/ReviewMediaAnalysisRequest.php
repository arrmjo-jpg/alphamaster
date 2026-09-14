<?php

declare(strict_types=1);

namespace App\Modules\Media\Requests;

use App\Modules\Media\Enums\MediaAnalysisReviewDecision;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ReviewMediaAnalysisRequest extends FormRequest
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
            'decision' => ['required', 'string', Rule::enum(MediaAnalysisReviewDecision::class)],

            /** The reviewer's reasoning. Kept with the review; the audit trail records only that one was given. */
            'note' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
