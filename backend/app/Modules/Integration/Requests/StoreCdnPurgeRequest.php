<?php

declare(strict_types=1);

namespace App\Modules\Integration\Requests;

use App\Modules\Core\Delivery\EdgeInvalidation;
use App\Modules\Core\Delivery\EdgeInvalidationKind;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCdnPurgeRequest extends FormRequest
{
    /** At most this many items in one operator request; it is split per vendor call after. */
    public const MAX_ITEMS = 500;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * Each item is checked against the same shape rules the edge cache applies, so a
     * refusal names the item and the reason here rather than surfacing later on a queued
     * row.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'kind' => ['required', 'string', Rule::enum(EdgeInvalidationKind::class)],

            /**
             * What to purge: absolute URLs, tags, absolute URL prefixes or host names. Absent for everything.
             *
             * @var list<string>
             */
            'items' => ['array', 'max:'.self::MAX_ITEMS, 'required_unless:kind,everything', 'prohibited_if:kind,everything'],
            'items.*' => [
                'string',
                'max:2048',
                function (string $attribute, mixed $value, Closure $fail): void {
                    $kind = EdgeInvalidationKind::tryFrom((string) $this->input('kind'));

                    if ($kind === null || $kind === EdgeInvalidationKind::EVERYTHING || ! is_string($value)) {
                        return;
                    }

                    $problem = EdgeInvalidation::problem($kind, trim($value));

                    if ($problem !== null) {
                        $fail(__('validation.custom.cdn_item.'.$problem));
                    }
                },
            ],

            /** Why, in the operator's words. Kept on each purge request and in the audit trail. */
            'reason' => ['nullable', 'string', 'max:255'],

            /** Purging everything only: the verified scope's name, typed out. */
            'confirm' => ['required_if:kind,everything', 'nullable', 'string', 'max:255'],
        ];
    }
}
