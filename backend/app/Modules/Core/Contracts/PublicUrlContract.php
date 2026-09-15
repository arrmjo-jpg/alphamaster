<?php

declare(strict_types=1);

namespace App\Modules\Core\Contracts;

use App\Modules\Core\Seo\PublicRoute;

/**
 * The public address of any content, in any language (ADR 0058 §1).
 *
 * The platform is API-only and the site is rendered elsewhere, yet canonical links, hreflang
 * alternates, sitemaps and structured data all need absolute addresses. So each module
 * declares the pattern its content is published at — `/{locale}/pages/{slug}` — and Core
 * composes the address on the configured public origin. The frontend follows the patterns;
 * it never composes a canonical or an alternate of its own.
 */
interface PublicUrlContract
{
    /**
     * Declare where a type of content is published. Declaring the same type twice with a
     * different pattern is a programming error.
     */
    public function register(PublicRoute $route): void;

    public function has(string $type): bool;

    /**
     * The path, from the pattern alone, or null when the type is not declared or a parameter
     * it needs is missing.
     *
     * @param  array<string, string>  $params
     */
    public function path(string $type, string $locale, array $params): ?string;

    /**
     * The absolute address, or null when no public origin is configured, the type is not
     * declared, or a parameter is missing.
     *
     * @param  array<string, string>  $params
     */
    public function url(string $type, string $locale, array $params): ?string;
}
