<?php

declare(strict_types=1);

namespace App\Modules\Media\Contracts;

use App\Modules\Media\Models\MediaFile;

/**
 * Rewrites a storage URL into a delivery URL.
 *
 * No CDN vendor is named anywhere in Media. Configuration lives in the Settings
 * module under the cdn group rather than in a second configuration system of Media's
 * own, and the default resolver returns the storage URL unchanged, so a platform with
 * no CDN behaves correctly rather than specially.
 */
interface CdnUrlResolverContract
{
    /**
     * The URL a client should fetch, given the storage URL.
     */
    public function resolve(MediaFile $media, string $storageUrl): string;

    /**
     * Whether a CDN is actually in front of storage.
     */
    public function isEnabled(): bool;
}
