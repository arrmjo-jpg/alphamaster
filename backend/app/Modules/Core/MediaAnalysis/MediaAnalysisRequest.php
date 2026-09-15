<?php

declare(strict_types=1);

namespace App\Modules\Core\MediaAnalysis;

use InvalidArgumentException;

/**
 * A consumer's explicit request to analyse one stored media file (ADR 0054).
 *
 * The consumer names itself — `news.article_video`, `competitions.submission` — so an
 * operator reading the analyses can tell which use case asked, and so a consumer listening
 * for results can recognise its own. The name is an identifier, never a class: Core does
 * not know, and must not learn, which modules exist.
 *
 * A malformed request is a programming error and is refused with an exception. Everything
 * that depends on the platform's state — switched off, unconfigured, unsupported — is an
 * outcome on the ticket instead.
 */
final readonly class MediaAnalysisRequest
{
    private const CONSUMER = '/^[a-z][a-z0-9_]*(\.[a-z][a-z0-9_]*)*$/';

    /**
     * @param  list<MediaAnalysisType>  $types
     */
    private function __construct(
        public string $mediaId,
        public array $types,
        public string $consumer,
        public bool $reanalyze,
        public ?string $reason,
    ) {}

    /**
     * @param  iterable<MediaAnalysisType|string>  $types
     * @param  bool  $reanalyze  run again even when an equivalent analysis exists, and supersede the current one
     */
    public static function for(
        string $mediaId,
        iterable $types,
        string $consumer,
        bool $reanalyze = false,
        ?string $reason = null,
    ): self {
        if (trim($mediaId) === '') {
            throw new InvalidArgumentException('A media analysis names the media it analyses.');
        }

        if (strlen($consumer) > 100 || preg_match(self::CONSUMER, $consumer) !== 1) {
            throw new InvalidArgumentException("Media analysis consumer [{$consumer}] must be a lower-case dotted identifier.");
        }

        $resolved = [];

        foreach ($types as $type) {
            $case = $type instanceof MediaAnalysisType ? $type : MediaAnalysisType::tryFrom((string) $type);

            if ($case === null) {
                throw new InvalidArgumentException('['.(string) $type.'] is not a media analysis type.');
            }

            $resolved[$case->value] = $case;
        }

        if ($resolved === []) {
            throw new InvalidArgumentException('A media analysis asks for at least one type.');
        }

        if ($reason !== null && mb_strlen($reason) > 255) {
            throw new InvalidArgumentException('A media analysis reason is at most 255 characters.');
        }

        return new self($mediaId, array_values($resolved), $consumer, $reanalyze, $reason);
    }

    /**
     * @return list<string>
     */
    public function typeValues(): array
    {
        return array_map(static fn (MediaAnalysisType $type): string => $type->value, $this->types);
    }
}
