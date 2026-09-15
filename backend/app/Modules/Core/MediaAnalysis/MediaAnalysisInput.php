<?php

declare(strict_types=1);

namespace App\Modules\Core\MediaAnalysis;

use Closure;

/**
 * What an analyzer is given (ADR 0054).
 *
 * The bytes are not in it. A driver asks for a stream or a short-lived URL, and only at
 * the moment it needs one, so a file is read or handed to a vendor only by an analysis that
 * is actually running — never by building this object. Neither the storage path nor the
 * disk is exposed.
 */
final readonly class MediaAnalysisInput
{
    /**
     * @param  list<string>  $types  the types to analyse, all supported by the analyzer
     * @param  Closure(): mixed  $stream  opens a readable stream, or returns null
     * @param  Closure(int): (string|null)  $temporaryUrl  a URL valid for the given seconds, or null when storage cannot issue one
     */
    public function __construct(
        public string $analysisId,
        public string $mediaId,
        public string $mimeType,
        public int $sizeBytes,
        public ?int $durationSeconds,
        public string $checksum,
        public array $types,
        private Closure $stream,
        private Closure $temporaryUrl,
    ) {}

    /**
     * @return resource|null
     */
    public function openStream()
    {
        $stream = ($this->stream)();

        return is_resource($stream) ? $stream : null;
    }

    public function temporaryUrl(int $ttlSeconds): ?string
    {
        $url = ($this->temporaryUrl)($ttlSeconds);

        return is_string($url) && $url !== '' ? $url : null;
    }
}
