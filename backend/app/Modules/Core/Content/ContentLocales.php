<?php

declare(strict_types=1);

namespace App\Modules\Core\Content;

use App\Modules\Core\Contracts\LocaleResolverInterface;

/**
 * The languages localized content is written and served in, as Language Management defines
 * them (ADR 0055 §2).
 *
 * Content modules never keep a list of codes. A language is *known* once it exists, and may
 * then be written; it is *served* once it is active, and only then appears publicly (ADR 0048).
 * The default language is the one a page cannot be published without.
 */
class ContentLocales
{
    public function __construct(private readonly LocaleResolverInterface $resolver) {}

    /**
     * Every language content may be written in, default first.
     *
     * @return list<string>
     */
    public function known(): array
    {
        return $this->ordered($this->resolver->getKnownLocaleCodes());
    }

    /**
     * Every language content may be served in, default first.
     *
     * @return list<string>
     */
    public function served(): array
    {
        return $this->ordered($this->resolver->getActiveLanguageCodes());
    }

    public function default(): string
    {
        return $this->resolver->getDefaultLocale();
    }

    public function isKnown(string $locale): bool
    {
        return in_array($locale, $this->resolver->getKnownLocaleCodes(), true);
    }

    public function isServed(string $locale): bool
    {
        return in_array($locale, $this->resolver->getActiveLanguageCodes(), true);
    }

    public function direction(string $locale): string
    {
        return $this->resolver->getDirection($locale);
    }

    /**
     * The default language first, then the order Language Management gives.
     *
     * @param  array<int, string>  $codes
     * @return list<string>
     */
    public function ordered(array $codes): array
    {
        $default = $this->default();
        $codes = array_values(array_unique($codes));

        if (! in_array($default, $codes, true)) {
            return $codes;
        }

        return [$default, ...array_values(array_filter($codes, static fn (string $code): bool => $code !== $default))];
    }
}
