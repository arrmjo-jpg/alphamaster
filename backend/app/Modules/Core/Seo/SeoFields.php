<?php

declare(strict_types=1);

namespace App\Modules\Core\Seo;

use Illuminate\Validation\Rule;

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

    public const TITLE_MAX = 255;

    public const DESCRIPTION_MAX = 1000;

    public const CANONICAL_MAX = 2048;

    /**
     * The validation for one language's SEO, under a key prefix.
     *
     * Every module that carries SEO validates it with these rules rather than restating them,
     * so two consumers cannot drift apart on what a canonical address or a robots value may
     * be. A canonical address must be http or https: a `javascript:` or `data:` value never
     * reaches a page's head.
     *
     * @return array<string, list<mixed>>
     */
    public static function rules(string $prefix = 'seo'): array
    {
        return [
            $prefix => ['sometimes', 'nullable', 'array'],
            "{$prefix}.title" => ['sometimes', 'nullable', 'string', 'max:'.self::TITLE_MAX],
            "{$prefix}.description" => ['sometimes', 'nullable', 'string', 'max:'.self::DESCRIPTION_MAX],
            "{$prefix}.robots" => ['sometimes', 'nullable', 'string', Rule::in(self::ROBOTS)],
            "{$prefix}.canonical_url" => ['sometimes', 'nullable', 'string', 'max:'.self::CANONICAL_MAX, 'url:http,https'],
            "{$prefix}.og_title" => ['sometimes', 'nullable', 'string', 'max:'.self::TITLE_MAX],
            "{$prefix}.og_description" => ['sometimes', 'nullable', 'string', 'max:'.self::DESCRIPTION_MAX],
            "{$prefix}.og_media_id" => ['sometimes', 'nullable', 'string', 'ulid'],
        ];
    }

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
