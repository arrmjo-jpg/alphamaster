<?php

declare(strict_types=1);

namespace App\Modules\Core\Seo;

/**
 * What an operator set for search and sharing, in one language (ADR 0032, ADR 0055 §7).
 *
 * Every field is optional. An unset field is resolved from the content itself, in the same
 * language, and never from another language.
 */
final readonly class SeoFields
{
    public const FIELDS = ['title', 'description', 'robots', 'canonical_url', 'og_title', 'og_description', 'og_media_id'];

    public const ROBOTS = ['index,follow', 'noindex,follow', 'index,nofollow', 'noindex,nofollow'];

    public function __construct(
        public ?string $title = null,
        public ?string $description = null,
        public ?string $robots = null,
        public ?string $canonicalUrl = null,
        public ?string $ogTitle = null,
        public ?string $ogDescription = null,
        public ?string $ogMediaId = null,
    ) {}

    /**
     * @param  array<string, mixed>  $values  keyed by the names in FIELDS
     */
    public static function fromArray(array $values): self
    {
        $text = static function (mixed $value): ?string {
            if (! is_string($value)) {
                return null;
            }

            $value = trim($value);

            return $value === '' ? null : $value;
        };

        return new self(
            title: $text($values['title'] ?? null),
            description: $text($values['description'] ?? null),
            robots: $text($values['robots'] ?? null),
            canonicalUrl: $text($values['canonical_url'] ?? null),
            ogTitle: $text($values['og_title'] ?? null),
            ogDescription: $text($values['og_description'] ?? null),
            ogMediaId: $text($values['og_media_id'] ?? null),
        );
    }

    /**
     * @return array{title: string|null, description: string|null, robots: string|null, canonical_url: string|null, og_title: string|null, og_description: string|null, og_media_id: string|null}
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
            'og_media_id' => $this->ogMediaId,
        ];
    }

    public function isEmpty(): bool
    {
        return array_filter($this->toArray(), static fn (?string $value): bool => $value !== null) === [];
    }
}
