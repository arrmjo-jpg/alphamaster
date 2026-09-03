<?php

declare(strict_types=1);

namespace App\Modules\Media\Services;

use App\Modules\Media\Contracts\MediaProcessorContract;
use App\Modules\Media\Enums\MediaType;

/**
 * Resolves the processor for a media type, if one is registered.
 *
 * Returns null rather than throwing when a type has no processor: this environment
 * has no gd, imagick or ffprobe, so a video legitimately has nothing to run, and that
 * is an absence rather than an error.
 */
class ProcessorRegistry
{
    /**
     * @var array<string, MediaProcessorContract>
     */
    private array $processors = [];

    /**
     * @param  array<int, MediaProcessorContract>  $processors
     */
    public function __construct(array $processors = [])
    {
        foreach ($processors as $processor) {
            $this->processors[$processor->handles()->value] = $processor;
        }
    }

    public function for(MediaType $type): ?MediaProcessorContract
    {
        return $this->processors[$type->value] ?? null;
    }
}
