<?php

declare(strict_types=1);

namespace App\Modules\Core\Contracts;

/**
 * The site's own SEO values, as Core resolves content against them (ADR 0058 §4).
 *
 * Declared here because they are settings and Core may not depend on Settings; Settings
 * implements it. Every localized answer is the requested language's own: a language with no
 * site name has none, and is never given another language's.
 */
interface SiteSeoDefaultsContract
{
    /** The site's name in this language, or null. */
    public function title(string $locale): ?string;

    /** The site's description in this language, or null. */
    public function description(string $locale): ?string;

    /** The default sharing image, when it is a public image ready to serve. */
    public function imageUrl(): ?string;

    /** The site's robots policy: one of `SeoFields::ROBOTS`. */
    public function robots(): string;

    /**
     * The origin public addresses are composed on, without a trailing slash, or null when
     * none is configured. Nothing invents one.
     */
    public function publicOrigin(): ?string;

    /** The extra robots.txt lines an operator configured, unfiltered. */
    public function robotsExtra(): ?string;
}
