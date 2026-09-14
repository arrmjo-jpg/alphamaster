<?php

declare(strict_types=1);

namespace App\Modules\Integration\Data;

/**
 * What a CDN said about the scope a provider is configured for — a zone, a pull zone, a
 * distribution — when asked (ADR 0053 §4).
 *
 * The plan is read from the vendor rather than chosen by an operator: the limits that
 * follow from it are the vendor's, and a plan typed into a form is a plan nobody verified.
 */
final readonly class CdnScopeReport
{
    private function __construct(
        public bool $reachable,
        public ?string $name = null,
        public ?string $status = null,
        public ?string $plan = null,
        public ?string $errorCode = null,
        public ?string $errorMessage = null,
    ) {}

    public static function reachable(string $name, ?string $status, ?string $plan): self
    {
        return new self(reachable: true, name: $name, status: $status, plan: $plan);
    }

    public static function unreachable(string $code, string $message): self
    {
        return new self(reachable: false, errorCode: $code, errorMessage: mb_substr($message, 0, 500));
    }
}
