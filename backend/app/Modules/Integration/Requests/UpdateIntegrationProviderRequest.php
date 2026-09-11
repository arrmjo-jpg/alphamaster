<?php

declare(strict_types=1);

namespace App\Modules\Integration\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateIntegrationProviderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * capability and driver are absent on purpose: they identify which integration a
     * row is, not how it is configured, and changing them would silently repoint a
     * provider at a different vendor's contract.
     *
     * The two docblocks below are the published shape of fields the rules alone
     * cannot describe. `settings.*` and `credentials.*` say what each value must be
     * and say nothing about the key, so the generator read both as lists and gave
     * every client an array of strings for a field that is a keyed map. The rules are
     * unchanged; the annotation states the key type they already imply.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'label' => ['sometimes', 'string', 'max:100'],

            /**
             * Non-secret configuration, keyed by setting name. Sending null clears every setting.
             *
             * @var array<string, string|null>|null
             */
            'settings' => ['sometimes', 'nullable', 'array'],
            'settings.*' => ['nullable', 'string', 'max:500'],

            /**
             * Vendor credentials, keyed by credential name. Omitting the field leaves the stored credentials untouched; sending null clears them. They are never read back.
             *
             * @var array<string, string>|null
             */
            'credentials' => ['sometimes', 'nullable', 'array'],
            // `filled` rather than `required`, and it is the same rule for an array
            // member: every key present in the map is by definition present, so both
            // reject a null or empty value and neither can reject anything else. It
            // is written this way because `required` here also marked the whole
            // `credentials` field required in the published contract — which would
            // have obliged every client to send credentials to change a label, and so
            // to overwrite a stored secret on an edit that had nothing to do with it.
            'credentials.*' => ['filled', 'string', 'max:500'],

            'is_active' => ['sometimes', 'boolean'],
            'priority' => ['sometimes', 'integer', 'min:0', 'max:1000'],
        ];
    }
}
