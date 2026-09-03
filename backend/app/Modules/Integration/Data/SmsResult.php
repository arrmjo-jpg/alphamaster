<?php

declare(strict_types=1);

namespace App\Modules\Integration\Data;

/**
 * The outcome of one send attempt against one driver.
 *
 * A driver reports failure by returning this rather than throwing, so the dispatcher
 * can record the attempt and move to the next provider without treating an expected
 * vendor rejection as an exceptional condition.
 */
final readonly class SmsResult
{
    private function __construct(
        public bool $successful,
        public string $driver,
        public ?string $reference = null,
        public ?string $errorCode = null,
        public ?string $errorMessage = null,
    ) {}

    public static function success(string $driver, ?string $reference = null): self
    {
        return new self(true, $driver, $reference);
    }

    public static function failure(string $driver, string $errorCode, string $errorMessage): self
    {
        return new self(false, $driver, null, $errorCode, $errorMessage);
    }
}
