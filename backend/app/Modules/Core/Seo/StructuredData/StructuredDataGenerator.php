<?php

declare(strict_types=1);

namespace App\Modules\Core\Seo\StructuredData;

use App\Modules\Core\Seo\ResolvedSeo;
use Illuminate\Database\Eloquent\Model;

/**
 * One type of content, described as a schema.org node (ADR 0058 §5).
 *
 * A module registers a generator for its own content — a page is a `WebPage`, a team member a
 * `Person` — and Core supplies the site's `WebSite` and `Organization` around it. The node is
 * built from values that are already resolved and public: a generator is handed nothing a
 * draft or a private file could leak through.
 */
interface StructuredDataGenerator
{
    /** The content type this describes, as its public route type names it. */
    public function key(): string;

    /**
     * @return array<string, mixed> one schema.org node, with `@type`
     */
    public function node(Model $owner, string $locale, ResolvedSeo $seo, ?string $url): array;
}
