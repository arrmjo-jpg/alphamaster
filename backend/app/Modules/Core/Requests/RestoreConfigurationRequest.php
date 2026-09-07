<?php

declare(strict_types=1);

namespace App\Modules\Core\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class RestoreConfigurationRequest extends FormRequest
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
            // A path on the configured disk, constrained so it cannot climb out of it.
            // The disk is where the deployment's own access controls apply; a location
            // that could contain "../" would let a restore read whatever the process can.
            'location' => ['required', 'string', 'max:255', 'regex:/^[A-Za-z0-9._\\/-]+$/', 'not_regex:/\\.\\./'],
        ];
    }
}
