<?php

declare(strict_types=1);

namespace App\Modules\Settings\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateGroupSettingsRequest extends FormRequest
{
    /**
     * Maximum number of settings that may be updated in a single batch.
     */
    private const MAX_KEYS = 100;

    /**
     * Maximum stored size, in bytes, of a single serialized value.
     */
    private const MAX_VALUE_BYTES = 60000;

    /**
     * Maximum nesting depth accepted for a json-typed value.
     */
    private const MAX_DEPTH = 8;

    /**
     * Determine if the user is authorized to make this request.
     *
     * Authorization is enforced by the route's admin perimeter
     * (auth:sanctum + ability:admin:access + active + admin).
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'settings' => ['required', 'array', 'min:1', 'max:'.self::MAX_KEYS],
        ];
    }

    /**
     * Structural validation of the batch payload.
     *
     * The service layer rejects values that do not fit a setting's declared type; this
     * pass rejects payloads that are malformed before type resolution is even possible
     * — non-identifier keys, unbounded nesting, and oversized values.
     *
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $settings = $this->input('settings');

                if (! is_array($settings)) {
                    return;
                }

                foreach ($settings as $key => $value) {
                    $this->validateKey($validator, $key);
                    $this->validateValue($validator, (string) $key, $value);
                }
            },
        ];
    }

    /**
     * Custom validation messages.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'settings.required' => 'A settings object is required.',
            'settings.array' => 'The settings field must be an object of key/value pairs.',
            'settings.max' => 'A batch update may contain at most '.self::MAX_KEYS.' settings.',
        ];
    }

    /**
     * A key must look like a setting identifier, not a list index or arbitrary text.
     */
    private function validateKey(Validator $validator, mixed $key): void
    {
        if (! is_string($key) || preg_match('/^[a-z][a-z0-9_]{0,99}$/', $key) !== 1) {
            $validator->errors()->add(
                'settings',
                'Setting keys must be lowercase identifiers matching [a-z][a-z0-9_]*.'
            );
        }
    }

    /**
     * A value must be null, a scalar, or a bounded array (for json-typed settings).
     */
    private function validateValue(Validator $validator, string $key, mixed $value): void
    {
        if ($value === null || is_scalar($value)) {
            if (is_string($value) && strlen($value) > self::MAX_VALUE_BYTES) {
                $validator->errors()->add(
                    "settings.{$key}",
                    'The value exceeds the maximum size of '.self::MAX_VALUE_BYTES.' bytes.'
                );
            }

            return;
        }

        if (! is_array($value)) {
            $validator->errors()->add(
                "settings.{$key}",
                'The value must be null, a scalar, or an array.'
            );

            return;
        }

        if ($this->depthOf($value) > self::MAX_DEPTH) {
            $validator->errors()->add(
                "settings.{$key}",
                'The value is nested more than '.self::MAX_DEPTH.' levels deep.'
            );

            return;
        }

        $encoded = json_encode($value);

        if ($encoded === false) {
            $validator->errors()->add("settings.{$key}", 'The value is not encodable as JSON.');

            return;
        }

        if (strlen($encoded) > self::MAX_VALUE_BYTES) {
            $validator->errors()->add(
                "settings.{$key}",
                'The value exceeds the maximum size of '.self::MAX_VALUE_BYTES.' bytes.'
            );
        }
    }

    /**
     * Nesting depth of an array, counting the outermost array as level 1.
     *
     * @param  array<mixed>  $value
     */
    private function depthOf(array $value, int $current = 1): int
    {
        if ($current > self::MAX_DEPTH) {
            return $current;
        }

        $max = $current;

        foreach ($value as $item) {
            if (is_array($item)) {
                $max = max($max, $this->depthOf($item, $current + 1));
            }
        }

        return $max;
    }
}
