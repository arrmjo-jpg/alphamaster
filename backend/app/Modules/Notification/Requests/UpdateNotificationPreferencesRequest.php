<?php

declare(strict_types=1);

namespace App\Modules\Notification\Requests;

use App\Modules\Notification\Enums\NotificationChannel;
use App\Modules\Notification\Enums\NotificationType;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateNotificationPreferencesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Types and channels are constrained to the registries, so a preference row can
     * never name something the platform does not actually send.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'preferences' => ['required', 'array', 'min:1', 'max:100'],
            'preferences.*.type' => ['required', 'string', Rule::in(NotificationType::values())],
            'preferences.*.channel' => ['required', 'string', Rule::in(NotificationChannel::values())],
            'preferences.*.enabled' => ['required', 'boolean'],
        ];
    }
}
