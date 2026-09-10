<?php

declare(strict_types=1);

namespace App\Modules\Notification\Requests;

use App\Modules\Notification\Enums\AnnouncementAudience;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SendAnnouncementRequest extends FormRequest
{
    /**
     * Authorization is the route perimeter plus `notifications.send`.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * What an announcement carries.
     *
     * Subject and body are the two placeholders the `admin.announcement` template
     * declares, and they are what an operator writes. Everything else about the
     * message — how it is framed, which channels carry it, which language each
     * recipient reads it in — is already settled by the template, the recipient's
     * preferences and their locale, so none of it is a field here.
     *
     * The body is bounded rather than unbounded. It travels into an in-app record and
     * potentially an email, and a field with no ceiling is a field somebody eventually
     * pastes a document into.
     *
     * The reasoning sits in this docblock rather than beside the rules, because
     * Scramble publishes an inline comment above a rule as that field's public
     * description.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            /**
             * The line a recipient sees first.
             *
             * @var string
             */
            'subject' => ['required', 'string', 'max:255'],

            /**
             * What the announcement says. Substituted literally: a value is never
             * itself treated as a placeholder.
             *
             * @var string
             */
            'body' => ['required', 'string', 'max:2000'],

            /**
             * Who receives it.
             *
             * @var AnnouncementAudience
             */
            'audience' => ['required', Rule::enum(AnnouncementAudience::class)],
        ];
    }
}
