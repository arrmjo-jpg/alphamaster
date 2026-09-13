<?php

declare(strict_types=1);

namespace App\Modules\Integration\Data;

/**
 * The outcome of one push attempt against one driver.
 *
 * `tokenInvalid` is the field that matters beyond the usual pair. A vendor telling
 * the platform that a registration token no longer exists is authoritative and is not
 * a transient failure — retrying it forever is how a device registry becomes a table
 * of addresses nobody reads (ADR 0045 §5). It is carried here so the caller can
 * remove the row rather than having to parse a vendor's error string.
 */
final readonly class PushResult
{
    private function __construct(
        public bool $successful,
        public string $driver,
        public ?string $reference = null,
        public ?string $errorCode = null,
        public ?string $errorMessage = null,
        public bool $tokenInvalid = false,
    ) {}

    public static function success(string $driver, ?string $reference = null): self
    {
        return new self(true, $driver, $reference);
    }

    public static function failure(string $driver, string $errorCode, string $errorMessage): self
    {
        return new self(false, $driver, null, $errorCode, $errorMessage);
    }

    /**
     * The vendor says this address is dead.
     *
     * A separate constructor rather than a flag on `failure()`, so a driver has to
     * decide which of the two it is rather than defaulting into the destructive one.
     */
    public static function tokenRejected(string $driver, string $errorCode, string $errorMessage): self
    {
        return new self(false, $driver, null, $errorCode, $errorMessage, true);
    }
}
