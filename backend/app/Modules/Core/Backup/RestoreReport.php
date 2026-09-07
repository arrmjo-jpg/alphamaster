<?php

declare(strict_types=1);

namespace App\Modules\Core\Backup;

/**
 * What restoring one section did, and what it declined (ADR 0039).
 *
 * A restore validates before it writes and reports rather than coerces, so this is the
 * shape that answer comes back in: how many rows were written, and which were skipped
 * with why. It carries no values — the reasons are what an operator acts on, and the
 * export itself is where the values live.
 */
final class RestoreReport
{
    /**
     * @param  array<int, array{key: string, reason: string}>  $skipped
     */
    public function __construct(
        public readonly string $section,
        public readonly int $restored = 0,
        public readonly array $skipped = [],
    ) {}

    /**
     * @param  array<int, array{key: string, reason: string}>  $skipped
     */
    public static function of(string $section, int $restored, array $skipped = []): self
    {
        return new self($section, $restored, $skipped);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'section' => $this->section,
            'restored' => $this->restored,
            'skipped' => $this->skipped,
        ];
    }
}
