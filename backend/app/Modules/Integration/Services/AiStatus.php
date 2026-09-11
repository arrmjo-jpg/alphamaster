<?php

declare(strict_types=1);

namespace App\Modules\Integration\Services;

/**
 * What an operator needs to know about AI before they trust it.
 *
 * The three questions — is a vendor selected, does it hold a credential, does it
 * answer — are the same for every capability, so the answering lives in
 * `CapabilityStatus` and this names which capability is being asked about. Push asks
 * the same way (ADR 0045).
 */
class AiStatus
{
    public function __construct(
        private readonly AiManager $manager,
        private readonly CapabilityStatus $status,
    ) {}

    /**
     * @return array{
     *     configured: bool,
     *     provider: array{driver: string, label: string, has_credentials: bool}|null,
     *     available_drivers: array<int, string>,
     *     last_attempt: array{status: string, at: string, error_code: string|null, error_message: string|null, duration_ms: int|null, units: int|null}|null,
     *     recent_failures: int
     * }
     */
    public function describe(): array
    {
        return $this->status->describe($this->manager);
    }
}
