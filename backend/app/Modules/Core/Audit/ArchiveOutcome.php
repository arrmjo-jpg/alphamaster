<?php

declare(strict_types=1);

namespace App\Modules\Core\Audit;

/**
 * What an archival operation did, and whether anything left the active store
 * (ADR 0037, as extended).
 *
 * Carries a count and a location and no records. The archive is the artefact; this is
 * the receipt, and a receipt that reproduced the trail would be a second copy of it in
 * a response body.
 */
final class ArchiveOutcome
{
    private function __construct(
        public readonly int $count,
        public readonly ?string $location,
        public readonly bool $removed,
        public readonly ?string $failure = null,
        public readonly ?string $oldest = null,
        public readonly ?string $newest = null,
    ) {}

    /**
     * Nothing was old enough. Not a failure, and nothing was written — an empty
     * archive file would be an artefact implying a removal that never happened.
     */
    public static function nothingEligible(): self
    {
        return new self(0, null, false);
    }

    public static function archived(int $count, string $location, string $oldest, string $newest): self
    {
        return new self($count, $location, true, null, $oldest, $newest);
    }

    /**
     * The export was written but could not be read back and confirmed, so nothing was
     * removed. The file is left where it is: deleting the evidence that verification
     * failed is the one reaction guaranteed to make the problem harder to diagnose.
     */
    public static function unverified(int $count, ?string $location, string $failure): self
    {
        return new self($count, $location, false, $failure);
    }

    public function succeeded(): bool
    {
        return $this->failure === null;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'count' => $this->count,
            'location' => $this->location,
            'removed' => $this->removed,
            'window' => ['oldest' => $this->oldest, 'newest' => $this->newest],
            'failure' => $this->failure,
        ];
    }
}
