<?php

declare(strict_types=1);

namespace App\Modules\Notification\Resources;

use App\Modules\Notification\Enums\NotificationType;
use App\Modules\Notification\Models\NotificationRecord;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One in-app record as its recipient reads it.
 *
 * The wording is served from the record rather than re-rendered from the template.
 * A template is editable, and re-rendering would mean an operator correcting today's
 * wording silently rewrote what somebody was told last week — the record is what the
 * platform actually said, and it is the copy this returns.
 *
 * That is also why the locale is published: the message was rendered in the
 * recipient's language at the moment it was raised, and an account that has since
 * changed its preferred language would otherwise be given no clue why an older message
 * reads differently.
 *
 * @property-read NotificationRecord $resource
 */
class NotificationRecordResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $data = $this->resource->data;

        $type = NotificationType::tryFrom($this->text($data, 'type'));

        // The label sits beside the value it describes and never replaces it
        // (ADR 0030/0031).
        //
        // Each field below carries a `@var`, because Scramble infers a published type
        // from the expression it finds and a ternary over a mixed array member gives it
        // nothing to infer — the fields came out of the generator as empty schemas, and
        // a generated client would have typed them `unknown`.
        return [
            'id' => $this->resource->id,
            /*
             * Named rather than flattened, for the same reason the template resource
             * names it: the type is what a client groups and filters on, and a bare
             * string would leave it holding its own copy of the registry.
             */
            /** @var NotificationType|null */
            'type' => $type?->value,

            /** @var string */
            'type_label' => $type?->label() ?? '',

            /** @var string */
            'subject' => $this->text($data, 'subject'),

            /** @var string */
            'body' => $this->text($data, 'body'),

            /**
             * The language this message was rendered in when it was raised.
             *
             * @var string|null
             */
            'locale' => $this->text($data, 'locale') === '' ? null : $this->text($data, 'locale'),

            // Null until the recipient has read it. Reported as a moment rather than a
            // flag because an interface showing an inbox renders when, and a client
            // that only needs whether can compare against null.
            'read_at' => $this->resource->read_at?->toIso8601String(),
            'created_at' => $this->resource->created_at?->toIso8601String(),
        ];
    }

    /**
     * One string member of the stored payload, or an empty string.
     *
     * The payload is what the notification wrote at the time it was raised, and this
     * resource reads it back rather than trusting its shape: a row written by an older
     * version of a notification is still a row somebody has to be able to read.
     *
     * @param  array<string, mixed>  $data
     */
    private function text(array $data, string $key): string
    {
        $value = $data[$key] ?? null;

        return is_string($value) ? $value : '';
    }
}
