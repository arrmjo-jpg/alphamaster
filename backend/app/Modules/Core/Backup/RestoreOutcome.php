<?php

declare(strict_types=1);

namespace App\Modules\Core\Backup;

/**
 * What a restore wrote and what it declined (ADR 0039).
 *
 * `encrypted_restorable` is the fingerprint comparison's answer, reported rather than
 * inferred: an operator looking at a restore that skipped every credential needs to know
 * whether that was because the key differs or because the export never carried them.
 */
final class RestoreOutcome
{
    /**
     * @param  array<int, RestoreReport>  $reports
     */
    public function __construct(
        public readonly string $location,
        public readonly bool $encryptedRestorable,
        public readonly array $reports = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'location' => $this->location,
            'encrypted_restorable' => $this->encryptedRestorable,
            'sections' => array_map(static fn (RestoreReport $r): array => $r->toArray(), $this->reports),
        ];
    }
}
