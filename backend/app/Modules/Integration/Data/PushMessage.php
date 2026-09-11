<?php

declare(strict_types=1);

namespace App\Modules\Integration\Data;

/**
 * One push, and deliberately almost nothing in it.
 *
 * A notification **type** and the **id** of the in-app record, and no content at all
 * (ADR 0045 §4). No subject, no body, no name, no account detail.
 *
 * Two reasons, both concrete. A push payload passes through Google and Apple — it is
 * content this platform handed to a third party for delivery, and unlike an SMS there
 * is no reason it has to be readable in transit, because the device can fetch the
 * message over the same authenticated API it uses for everything else. And a lock
 * screen is a public surface: a body in a push is visible to anyone holding the phone.
 *
 * The cost is honest and worth stating: a device that is offline when it tries to
 * fetch shows something generic. That is the right trade.
 */
final readonly class PushMessage
{
    public function __construct(
        /** The device's registration token, from the registry. */
        public string $token,
        /** A technical identifier such as `security.alert`, never a sentence. */
        public string $type,
        /** The in-app record the device should fetch. */
        public string $recordId,
    ) {}

    /**
     * The payload, as every driver sends it.
     *
     * Built here rather than per driver so that adding a second transport cannot
     * quietly widen what leaves the platform.
     *
     * @return array<string, string>
     */
    public function data(): array
    {
        return [
            'type' => $this->type,
            'record_id' => $this->recordId,
        ];
    }
}
