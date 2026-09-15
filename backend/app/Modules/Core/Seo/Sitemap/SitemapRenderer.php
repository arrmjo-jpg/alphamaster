<?php

declare(strict_types=1);

namespace App\Modules\Core\Seo\Sitemap;

use App\Modules\Core\Contracts\SiteSeoDefaultsContract;
use App\Modules\Core\Delivery\EdgeCacheTag;

/**
 * Writes the sitemap index and each source's files (ADR 0058 §2).
 *
 * A file holds at most 50,000 addresses, the sitemap protocol's limit. The index names every
 * file at the public origin, `/sitemaps/{source}-{n}.xml`: the frontend or the edge serves the
 * API's `/api/v1/sitemap.xml` and `/api/v1/sitemaps/...` there, which is the one mapping it
 * owns. Without a public origin there is no absolute address to write, so nothing is written.
 */
final class SitemapRenderer
{
    public const MAX_URLS = 50000;

    private int $chunk = self::MAX_URLS;

    public function __construct(
        private readonly SitemapRegistry $sources,
        private readonly SiteSeoDefaultsContract $defaults,
    ) {}

    /** The edge tag the index carries. */
    public static function indexTag(): string
    {
        return EdgeCacheTag::for('sitemap', 'index');
    }

    /** The edge tag a source's files carry, purged when the source changes. */
    public static function sourceTag(string $key): string
    {
        return EdgeCacheTag::for('sitemap', $key);
    }

    /**
     * How many addresses a file holds. Lowered by tests to exercise chunking; never raised past
     * the protocol's limit.
     */
    public function chunkSize(int $size): void
    {
        $this->chunk = max(1, min(self::MAX_URLS, $size));
    }

    /** How many files a source is written in. */
    public function files(SitemapSource $source): int
    {
        return (int) ceil($source->count() / $this->chunk);
    }

    /** The sitemap index, or null when no public origin is configured. */
    public function index(): ?string
    {
        $origin = $this->origin();

        if ($origin === null) {
            return null;
        }

        $xml = '<?xml version="1.0" encoding="UTF-8"?>'."\n"
            .'<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'."\n";

        foreach ($this->sources->all() as $source) {
            for ($file = 1, $files = $this->files($source); $file <= $files; $file++) {
                $xml .= '  <sitemap><loc>'.self::escape("{$origin}/sitemaps/{$source->key()}-{$file}.xml").'</loc></sitemap>'."\n";
            }
        }

        return $xml.'</sitemapindex>'."\n";
    }

    /**
     * One file of one source, or null when there is no origin, no such source, or no such file.
     */
    public function file(string $key, int $file): ?string
    {
        $source = $this->sources->find($key);

        if ($this->origin() === null || $source === null || $file < 1 || $file > max(1, $this->files($source))) {
            return null;
        }

        $offset = ($file - 1) * $this->chunk;
        $seen = 0;
        $written = 0;

        $xml = '<?xml version="1.0" encoding="UTF-8"?>'."\n"
            .'<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:xhtml="http://www.w3.org/1999/xhtml">'."\n";

        foreach ($source->entries() as $entry) {
            if (! $entry->isIndexable()) {
                continue;
            }

            if ($seen++ < $offset) {
                continue;
            }

            if ($written === $this->chunk) {
                break;
            }

            $xml .= $this->url($entry);
            $written++;
        }

        return $xml.'</urlset>'."\n";
    }

    private function url(SitemapEntry $entry): string
    {
        $xml = "  <url>\n    <loc>".self::escape($entry->url)."</loc>\n";

        if ($entry->lastModified !== null) {
            $xml .= '    <lastmod>'.$entry->lastModified->format(DATE_ATOM)."</lastmod>\n";
        }

        foreach ($entry->alternates as $locale => $url) {
            $xml .= '    <xhtml:link rel="alternate" hreflang="'.self::escape((string) $locale).'" href="'.self::escape($url).'"/>'."\n";
        }

        if ($entry->defaultUrl !== null && $entry->alternates !== []) {
            $xml .= '    <xhtml:link rel="alternate" hreflang="x-default" href="'.self::escape($entry->defaultUrl).'"/>'."\n";
        }

        return $xml."  </url>\n";
    }

    private function origin(): ?string
    {
        $origin = $this->defaults->publicOrigin();

        return $origin === null ? null : rtrim($origin, '/');
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
