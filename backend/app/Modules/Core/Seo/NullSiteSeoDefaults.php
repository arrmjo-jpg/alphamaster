<?php

declare(strict_types=1);

namespace App\Modules\Core\Seo;

use App\Modules\Core\Contracts\SiteSeoDefaultsContract;

/**
 * No site configuration: nothing is invented (ADR 0058 §4).
 *
 * The robots policy is the one value with a platform default, because an absent policy is
 * "index and follow" in every search engine's reading.
 */
final class NullSiteSeoDefaults implements SiteSeoDefaultsContract
{
    public function title(string $locale): ?string
    {
        return null;
    }

    public function description(string $locale): ?string
    {
        return null;
    }

    public function imageUrl(): ?string
    {
        return null;
    }

    public function robots(): string
    {
        return SeoFields::DEFAULT_ROBOTS;
    }

    public function publicOrigin(): ?string
    {
        return null;
    }

    public function robotsExtra(): ?string
    {
        return null;
    }
}
