<?php

declare(strict_types=1);

namespace App\Modules\Core\Backup;

/**
 * Where an export went and what it left out (ADR 0039).
 *
 * A receipt, not a copy. It names the sections written and the secrets omitted, and
 * carries no configuration — the artefact on the disk is the artefact, and a response
 * body that reproduced it would be a second copy travelling by a different route.
 */
final class ExportOutcome
{
    /**
     * @param  array<int, string>  $sections
     * @param  array<int, string>  $omittedSecrets
     */
    public function __construct(
        public readonly string $location,
        public readonly array $sections,
        public readonly bool $includesSecrets,
        public readonly array $omittedSecrets = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'location' => $this->location,
            'sections' => $this->sections,
            'includes_secrets' => $this->includesSecrets,
            'omitted_secrets' => $this->omittedSecrets,
        ];
    }
}
