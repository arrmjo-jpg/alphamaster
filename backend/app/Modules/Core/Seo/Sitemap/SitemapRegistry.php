<?php

declare(strict_types=1);

namespace App\Modules\Core\Seo\Sitemap;

use LogicException;

/**
 * The sitemap sources modules have registered (ADR 0058 §2, ADR 0052).
 */
final class SitemapRegistry
{
    /** @var array<string, SitemapSource> */
    private array $sources = [];

    public function register(SitemapSource $source): void
    {
        $key = $source->key();

        if (preg_match('/^[a-z][a-z0-9_]*$/', $key) !== 1) {
            throw new LogicException("A sitemap source key is lower-case letters, digits and underscores: [{$key}].");
        }

        $existing = $this->sources[$key] ?? null;

        if ($existing !== null && $existing::class !== $source::class) {
            throw new LogicException("Sitemap source [{$key}] is already registered by ".$existing::class.'.');
        }

        $this->sources[$key] = $source;
    }

    public function find(string $key): ?SitemapSource
    {
        return $this->sources[$key] ?? null;
    }

    /**
     * @return list<SitemapSource>
     */
    public function all(): array
    {
        return array_values($this->sources);
    }
}
