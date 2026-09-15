<?php

declare(strict_types=1);

namespace App\Modules\Core\Seo\Sitemap;

/**
 * One module's public addresses, as the sitemap lists them (ADR 0058 §2).
 *
 * A module decides what it publishes — only live content, only in languages the platform
 * serves — and Core decides how that is written: chunked files, alternates, and what an
 * indexable address is. Registering a source is all a module does to appear in the sitemap.
 */
interface SitemapSource
{
    /** A lower-case key, used in the file name and the edge tag. */
    public function key(): string;

    /** How many addresses the source lists across every language, for the index. */
    public function count(): int;

    /**
     * Every address, in a stable order, lazily: a source may be large, and the renderer
     * reads only as far as the file it is writing.
     *
     * @return iterable<SitemapEntry>
     */
    public function entries(): iterable;
}
