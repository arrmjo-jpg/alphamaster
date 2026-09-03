<?php

declare(strict_types=1);

namespace App\Modules\Integration\Data;

/**
 * A message to send, independent of any vendor's request shape.
 */
final readonly class SmsMessage
{
    public function __construct(
        public string $to,
        public string $body,
    ) {}
}
