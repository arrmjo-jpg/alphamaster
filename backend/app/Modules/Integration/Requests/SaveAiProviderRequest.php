<?php

declare(strict_types=1);

namespace App\Modules\Integration\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * One AI provider's configuration, as the setup form submits it.
 *
 * The API key is its own named field rather than an entry in a credential map, so an
 * administrator never needs to know what the driver calls it. Leaving it out keeps the
 * stored key; it is never read back. Nothing about how the driver reaches the vendor —
 * an address, a header — is accepted: that belongs to the driver.
 */
class SaveAiProviderRequest extends FormRequest
{
    /**
     * A vendor's model identifier: letters, digits and the punctuation vendors use in
     * them. Enough to refuse something that is plainly not a model name.
     */
    public const MODEL_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9._:\/@-]*$/';

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            /** The vendor's API key. Omit it to keep the key already stored. */
            'api_key' => ['sometimes', 'nullable', 'string', 'max:500'],
            /** The model this provider answers with. */
            'model' => ['required', 'string', 'max:200', 'regex:'.self::MODEL_PATTERN],
        ];
    }
}
