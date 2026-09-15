<?php

declare(strict_types=1);

namespace App\Modules\Core\Seo;

/**
 * The search and sharing values a client renders for one piece of content in one language.
 *
 * Open Graph is the sharing metadata; there is no separate Twitter/X storage (ADR 0058 §6).
 * `twitter_card` is derived: a large image card when there is a sharing image, a summary
 * card otherwise, and X reads the Open Graph title, description and image for either.
 */
final readonly class ResolvedSeo
{
    public function __construct(
        public string $title,
        public ?string $description,
        public string $robots,
        public ?string $canonicalUrl,
        public string $ogTitle,
        public ?string $ogDescription,
        public ?string $ogImageUrl,
        public string $twitterCard = 'summary',
    ) {}

    /**
     * @return array{title: string, description: string|null, robots: string, canonical_url: string|null, og_title: string, og_description: string|null, og_image_url: string|null, twitter_card: string}
     */
    public function toArray(): array
    {
        return [
            'title' => $this->title,
            'description' => $this->description,
            'robots' => $this->robots,
            'canonical_url' => $this->canonicalUrl,
            'og_title' => $this->ogTitle,
            'og_description' => $this->ogDescription,
            'og_image_url' => $this->ogImageUrl,
            'twitter_card' => $this->twitterCard,
        ];
    }
}
