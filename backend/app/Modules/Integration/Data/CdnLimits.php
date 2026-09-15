<?php

declare(strict_types=1);

namespace App\Modules\Integration\Data;

use App\Modules\Core\Delivery\EdgeInvalidationKind;

/**
 * What a CDN will accept, for the plan the configured zone is on (ADR 0053 §4).
 *
 * Declared by the driver, because the numbers are the vendor's. A kind absent from
 * `itemsPerRequest` is one the vendor cannot purge. `requestsPerMinute` is the budget the
 * worker spends before calling; kinds sharing a budget name the same bucket.
 */
final readonly class CdnLimits
{
    /**
     * @param  array<string, int>  $itemsPerRequest  kind => items one call may carry
     * @param  array<string, int>  $requestsPerMinute  kind => calls a minute, absent when unbounded here
     * @param  array<string, string>  $buckets  kind => the budget it is counted against
     */
    public function __construct(
        public array $itemsPerRequest,
        public array $requestsPerMinute = [],
        public array $buckets = [],
        public ?string $plan = null,
    ) {}

    public function supports(EdgeInvalidationKind $kind): bool
    {
        return isset($this->itemsPerRequest[$kind->value]);
    }

    public function itemsPerRequest(EdgeInvalidationKind $kind): int
    {
        return max(1, $this->itemsPerRequest[$kind->value] ?? 1);
    }

    public function requestsPerMinute(EdgeInvalidationKind $kind): ?int
    {
        return $this->requestsPerMinute[$kind->value] ?? null;
    }

    public function bucket(EdgeInvalidationKind $kind): string
    {
        return $this->buckets[$kind->value] ?? $kind->value;
    }

    /**
     * @return list<array{kind: string, supported: bool, items_per_request: int|null, requests_per_minute: int|null}>
     */
    public function toArray(): array
    {
        return array_map(fn (EdgeInvalidationKind $kind): array => [
            'kind' => $kind->value,
            'supported' => $this->supports($kind),
            'items_per_request' => $this->supports($kind) ? $this->itemsPerRequest($kind) : null,
            'requests_per_minute' => $this->supports($kind) ? $this->requestsPerMinute($kind) : null,
        ], EdgeInvalidationKind::cases());
    }
}
