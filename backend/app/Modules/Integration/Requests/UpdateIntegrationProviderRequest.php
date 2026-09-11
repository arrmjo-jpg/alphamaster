<?php

declare(strict_types=1);

namespace App\Modules\Integration\Requests;

use App\Modules\Integration\Data\ServiceAccount;
use App\Modules\Integration\Models\IntegrationProvider;
use App\Modules\Integration\Rules\ServiceAccountJson;
use App\Modules\Integration\Rules\ServiceAccountPrivateKey;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateIntegrationProviderRequest extends FormRequest
{
    /**
     * The ceiling for an ordinary credential: an API key, a token, an account SID.
     */
    private const CREDENTIAL_MAX = 500;

    /**
     * The three fields FCM v1 reads from a Google service-account document, and the
     * only ones it may store (ADR 0045 §2).
     */
    private const SERVICE_ACCOUNT_FIELDS = ['project_id', 'client_email', 'private_key'];

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
     * A Firebase provider's credential is a different shape from every other, and is
     * validated as that shape rather than squeezed under the ordinary ceiling. It is a
     * service account — a private key, about 1,700 characters of PEM — and the 500
     * characters that bound an API key refused every real one, so the key could never
     * be saved at all. Raising the ceiling for everybody would have fixed the symptom
     * and let any driver store three pages of text; instead the Firebase provider takes
     * exactly its three fields, each checked for what it is, and every other driver
     * keeps the limit it had.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $serviceAccount = $this->takesServiceAccount();

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
            'credentials' => $serviceAccount
                ? ['sometimes', 'nullable', 'array:'.implode(',', self::SERVICE_ACCOUNT_FIELDS)]
                : ['sometimes', 'nullable', 'array'],
            // `filled` rather than `required`, and it is the same rule for an array
            // member: every key present in the map is by definition present, so both
            // reject a null or empty value and neither can reject anything else. It
            // is written this way because `required` here also marked the whole
            // `credentials` field required in the published contract — which would
            // have obliged every client to send credentials to change a label, and so
            // to overwrite a stored secret on an edit that had nothing to do with it.
            'credentials.*' => [
                'filled',
                'string',
                'max:'.($serviceAccount ? ServiceAccountPrivateKey::MAX_LENGTH : self::CREDENTIAL_MAX),
            ],

            ...($serviceAccount ? $this->serviceAccountRules() : []),

            /**
             * A Google service-account file, pasted whole. Firebase providers only. It
             * replaces the stored credentials in full, and only the project, the client
             * address and the private key are kept. Remove credentials with
             * `credentials: null`; omitting this leaves them as they are.
             *
             * @var string
             */
            'service_account_json' => [
                'sometimes',
                'bail',
                Rule::prohibitedIf(! $serviceAccount),
                'prohibits:credentials',
                'string',
                'max:'.ServiceAccount::MAX_DOCUMENT_LENGTH,
                new ServiceAccountJson,
            ],

            'is_active' => ['sometimes', 'boolean'],
            'priority' => ['sometimes', 'integer', 'min:0', 'max:1000'],
        ];
    }

    /**
     * Each of the three fields, checked for what it is.
     *
     * All three are required once credentials are sent at all: a credential write is a
     * replacement (the Admin says so), and a service account missing its key or its
     * email is not a partial update — it is a provider that cannot authenticate.
     *
     * The project id follows Google's own rule (6–30 characters, a lowercase letter
     * first, no trailing hyphen). The address has to be a service account's, because
     * FCM v1 accepts nothing else as the issuer of the assertion. The key must load.
     *
     * @return array<string, array<int, ValidationRule|string>>
     */
    private function serviceAccountRules(): array
    {
        return [
            'credentials.project_id' => [
                'required_with:credentials',
                'string',
                'regex:'.ServiceAccount::PROJECT_ID_PATTERN,
            ],
            'credentials.client_email' => [
                'required_with:credentials',
                'string',
                'max:254',
                'email',
                'regex:'.ServiceAccount::CLIENT_EMAIL_PATTERN,
            ],
            'credentials.private_key' => [
                'required_with:credentials',
                'string',
                new ServiceAccountPrivateKey,
            ],
        ];
    }

    /**
     * Whether the provider being updated authenticates with a service account.
     *
     * Read from the bound route model: the driver is fixed for a row and not part of
     * the request (see `rules()`), so the shape of its credential is known before any
     * input is read.
     */
    private function takesServiceAccount(): bool
    {
        $provider = $this->route('provider');

        return $provider instanceof IntegrationProvider && $provider->driver === 'fcm';
    }
}
