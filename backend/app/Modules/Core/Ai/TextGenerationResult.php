<?php

declare(strict_types=1);

namespace App\Modules\Core\Ai;

/**
 * What came back, or why nothing did.
 *
 * A driver reports a vendor's refusal by returning a failure rather than throwing,
 * for the reason ADR 0017 gives for SMS: an expected refusal is an outcome, and
 * exceptions are reserved for programming and configuration faults.
 *
 * `units` is what the vendor said it charged — tokens, for every driver so far. It is
 * recorded and never converted: prices change, and a stored cost is wrong
 * retroactively in a table nobody re-reads (ADR 0044 §8).
 */
final readonly class TextGenerationResult
{
    private function __construct(
        public bool $successful,
        public string $driver,
        public string $text = '',
        public ?int $units = null,
        public ?string $errorCode = null,
        public ?string $errorMessage = null,
    ) {}

    public static function success(string $driver, string $text, ?int $units = null): self
    {
        return new self(true, $driver, $text, $units);
    }

    public static function failure(string $driver, string $errorCode, string $errorMessage): self
    {
        return new self(false, $driver, '', null, $errorCode, $errorMessage);
    }
}
