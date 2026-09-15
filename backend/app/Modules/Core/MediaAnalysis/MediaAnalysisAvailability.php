<?php

declare(strict_types=1);

namespace App\Modules\Core\MediaAnalysis;

/**
 * Whether the capability can be used at all right now, and why not (ADR 0054).
 *
 * Read by a consumer that offers analysis as an option, so it can leave the option out
 * rather than offer one that is refused. It answers about the platform — switched on,
 * configured, which types the configured analyzer supports — and never about whether a
 * particular consumer wants to use it, which is that consumer's own decision.
 */
final readonly class MediaAnalysisAvailability
{
    public const DISABLED = 'disabled';

    public const NOT_CONFIGURED = 'not_configured';

    /**
     * @param  list<string>  $supportedTypes
     */
    private function __construct(
        public bool $available,
        public ?string $reason,
        public array $supportedTypes,
    ) {}

    /**
     * @param  list<string>  $supportedTypes
     */
    public static function available(array $supportedTypes): self
    {
        return new self(true, null, $supportedTypes);
    }

    public static function unavailable(string $reason): self
    {
        return new self(false, $reason, []);
    }

    public function supports(MediaAnalysisType|string $type): bool
    {
        $value = $type instanceof MediaAnalysisType ? $type->value : $type;

        return $this->available && in_array($value, $this->supportedTypes, true);
    }
}
