<?php

declare(strict_types=1);

namespace App\Modules\Core\Seo;

/**
 * The search and sharing values a client renders for one piece of content in one language.
 */
final readonly class ResolvedSeo
{
    public function __construct(
        public string $title,
        public ?string $description,
        public ?string $robots,
        public ?string $canonicalUrl,
        public string $ogTitle,
        public ?string $ogDescription,
        public ?string $ogImageUrl,
    ) {}

    /**
     * @return array{title: string, description: string|null, robots: string|null, canonical_url: string|null, og_title: string, og_description: string|null, og_image_url: string|null}
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
        ];
    }
}
