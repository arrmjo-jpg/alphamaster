<?php

declare(strict_types=1);

namespace App\Modules\Notification\Requests;

use App\Modules\Notification\Enums\DevicePlatform;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A device asking to be reachable.
 */
class RegisterPushDeviceRequest extends FormRequest
{
    /**
     * Authorization is the route's: the perimeter is middleware, and the endpoint acts
     * on whoever is asking, so there is nobody else's account to reach.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            // The vendor's registration token. Long, and lengthened by vendors before
            // now, so the ceiling is generous rather than exact.
            'token' => ['required', 'string', 'max:512'],

            // Generated and kept by the client. This is what makes a token replaceable
            // rather than additive: FCM rotates tokens, and without a stable handle for
            // the handset a rotation leaves two rows and delivers twice.
            'device_id' => ['required', 'string', 'max:128'],

            'platform' => ['required', 'string', Rule::in(DevicePlatform::values())],

            // What a person would recognise in a list of their own devices. Display
            // only, and never trusted for anything else.
            'label' => ['sometimes', 'nullable', 'string', 'max:120'],
        ];
    }
}
