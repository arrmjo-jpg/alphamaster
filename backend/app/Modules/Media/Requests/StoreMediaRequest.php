<?php

declare(strict_types=1);

namespace App\Modules\Media\Requests;

use App\Modules\Media\Enums\MediaVisibility;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreMediaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Shape only. What the file actually is gets decided from its bytes by
     * UploadValidator, because every value here is supplied by the client: the mime
     * rule below checks a claim, not a fact, and is not relied upon for safety.
     *
     * The ceiling is the platform's, not a number written here.
     * `branding.max_upload_kilobytes` has been configurable with a validated range
     * since Phase 16A and this rule hard-coded a hundred megabytes instead, so an
     * operator lowering the limit changed nothing and the only real ceiling was
     * nginx's `client_max_body_size`. Two limits and neither of them the one an
     * operator set.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            // The published description says which setting decides the ceiling rather
            // than what it is set to. Scramble derives a description from the `max:`
            // rule otherwise, and would bake one deployment's configured value into a
            // committed contract that every other deployment reads.
            /**
             * The file to upload. Its size must be within the maximum this platform is
             * configured to accept.
             */
            'file' => ['required', 'file', 'max:'.$this->maximumKilobytes()],
            'collection' => ['sometimes', 'string', 'max:60', 'regex:/^[a-z][a-z0-9_]*$/'],
            'visibility' => ['sometimes', 'string', Rule::in(MediaVisibility::values())],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'collection.regex' => __('validation.custom.collection.regex'),
            'file.max' => __('validation.custom.file.max'),
        ];
    }

    /**
     * The configured ceiling, defended against a setting that is absent or nonsense.
     *
     * The same reading the password minimum does, and for the same reason: a platform
     * whose limits live in settings should apply them wherever the limit is checked,
     * not only where somebody remembered. The fallback is the setting's own default.
     */
    private function maximumKilobytes(): int
    {
        $configured = setting('branding.max_upload_kilobytes', 10240);

        return is_int($configured) && $configured > 0 ? $configured : 10240;
    }
}
