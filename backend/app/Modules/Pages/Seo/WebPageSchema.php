<?php

declare(strict_types=1);

namespace App\Modules\Pages\Seo;

use App\Modules\Core\Seo\ResolvedSeo;
use App\Modules\Core\Seo\StructuredData\StructuredDataGenerator;
use App\Modules\Pages\Models\Page;
use Illuminate\Database\Eloquent\Model;

/**
 * A page as a schema.org `WebPage` (ADR 0058 §5).
 */
final class WebPageSchema implements StructuredDataGenerator
{
    public function key(): string
    {
        return 'pages';
    }

    public function node(Model $owner, string $locale, ResolvedSeo $seo, ?string $url): array
    {
        $page = $owner instanceof Page ? $owner : null;

        return [
            '@type' => 'WebPage',
            '@id' => $url === null ? null : $url.'#webpage',
            'url' => $seo->canonicalUrl ?? $url,
            'name' => $seo->title,
            'description' => $seo->description,
            'inLanguage' => $locale,
            'image' => $seo->ogImageUrl,
            'datePublished' => $page?->published_at?->toIso8601String(),
            'dateModified' => $page?->updated_at?->toIso8601String(),
        ];
    }
}
