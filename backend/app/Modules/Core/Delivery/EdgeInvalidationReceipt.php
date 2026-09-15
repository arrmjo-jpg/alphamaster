<?php

declare(strict_types=1);

namespace App\Modules\Core\Delivery;

/**
 * What became of an invalidation handed to the edge.
 *
 * Never "purged": a request is queued and processed by a worker, and its outcome is
 * recorded where an operator can see it (ADR 0036 — a purge is never reported as done
 * before the vendor said so).
 */
final readonly class EdgeInvalidationReceipt
{
    public const QUEUED = 'queued';

    /** No CDN driver is configured and active, so there is no edge to invalidate. */
    public const NOT_CONFIGURED = 'not_configured';

    /** The configured edge cannot invalidate this kind of scope. Nothing was queued. */
    public const UNSUPPORTED = 'unsupported';

    /**
     * @param  list<string>  $requestIds
     */
    public function __construct(
        public string $status,
        public array $requestIds = [],
    ) {}

    public static function notConfigured(): self
    {
        return new self(self::NOT_CONFIGURED);
    }

    public function queued(): bool
    {
        return $this->status === self::QUEUED;
    }
}
