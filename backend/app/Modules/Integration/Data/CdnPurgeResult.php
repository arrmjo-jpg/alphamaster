<?php

declare(strict_types=1);

namespace App\Modules\Integration\Data;

/**
 * What a CDN answered to one purge call.
 *
 * `retryable` is the driver's judgement of whether the same call could succeed later — a
 * rate limit or a vendor outage — as against one that cannot (a refused credential, a
 * malformed request). `retryAfterSeconds` is the vendor's own wait when it named one.
 */
final readonly class CdnPurgeResult
{
    private function __construct(
        public bool $successful,
        public ?string $reference = null,
        public ?string $errorCode = null,
        public ?string $errorMessage = null,
        public bool $retryable = false,
        public ?int $retryAfterSeconds = null,
    ) {}

    public static function success(?string $reference = null): self
    {
        return new self(successful: true, reference: $reference);
    }

    public static function failure(string $code, string $message, bool $retryable, ?int $retryAfterSeconds = null): self
    {
        return new self(
            successful: false,
            errorCode: $code,
            errorMessage: mb_substr($message, 0, 500),
            retryable: $retryable,
            retryAfterSeconds: $retryAfterSeconds,
        );
    }
}
