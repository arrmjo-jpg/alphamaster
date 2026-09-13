<?php

declare(strict_types=1);

namespace App\Modules\User\Requests;

use App\Modules\User\Enums\ProfileLinkPlatform;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProfileLinksRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * The whole list, in order. An empty list removes every link.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            /**
             * @var list<array{platform: string, url: string}>
             */
            'links' => ['present', 'array', 'max:10'],
            'links.*.platform' => ['required', 'string', Rule::in(ProfileLinkPlatform::values())],

            // ClientUrlPolicy's web-page rule: https in production, no credentials, no
            // fragment, never this machine.
            'links.*.url' => ['required', 'string', 'max:2048', 'url:http,https', 'client_page_url'],
        ];
    }
}
