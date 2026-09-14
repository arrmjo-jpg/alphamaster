<?php

declare(strict_types=1);

namespace App\Modules\Core\MediaAnalysis;

/**
 * What the configured analyzer is, and what it will accept (ADR 0054).
 *
 * Declared by the driver, because every fact in it is the vendor's: which types it can
 * score, which files it takes, how large and how long. The platform's own limits, set by an
 * operator, apply on top of these, and the stricter of the two wins.
 */
final readonly class AnalyzerDescriptor
{
    /**
     * @param  list<string>  $supportedTypes  media analysis type values
     * @param  list<string>  $acceptedMimeTypes  exact types (`video/mp4`) or prefixes ending in a slash (`video/`)
     */
    public function __construct(
        public string $provider,
        public string $analyzer,
        public ?string $modelVersion,
        public array $supportedTypes,
        public array $acceptedMimeTypes,
        public ?int $maxBytes = null,
        public ?int $maxDurationSeconds = null,
    ) {}

    public function supports(MediaAnalysisType|string $type): bool
    {
        return in_array($type instanceof MediaAnalysisType ? $type->value : $type, $this->supportedTypes, true);
    }

    public function accepts(string $mimeType): bool
    {
        $mimeType = strtolower(trim($mimeType));

        foreach ($this->acceptedMimeTypes as $accepted) {
            $accepted = strtolower($accepted);

            if (str_ends_with($accepted, '/') ? str_starts_with($mimeType, $accepted) : $mimeType === $accepted) {
                return true;
            }
        }

        return false;
    }
}
