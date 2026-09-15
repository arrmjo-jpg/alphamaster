<?php

declare(strict_types=1);

namespace App\Modules\Core\Seo;

use App\Modules\Core\Contracts\PublicUrlContract;
use App\Modules\Core\Contracts\SiteSeoDefaultsContract;
use LogicException;

/**
 * Composes public addresses from the patterns modules declare (ADR 0058 §1).
 *
 * Every parameter is percent-encoded, so an Arabic slug becomes a valid address rather than a
 * string a crawler has to guess at. The origin is read on each call: it is a setting an
 * operator can change, and a cached copy would compose yesterday's domain.
 */
final class PublicUrls implements PublicUrlContract
{
    /** @var array<string, PublicRoute> */
    private array $routes = [];

    public function register(PublicRoute $route): void
    {
        $existing = $this->routes[$route->type] ?? null;

        if ($existing !== null && $existing->pattern !== $route->pattern) {
            throw new LogicException("Public route [{$route->type}] is already declared as [{$existing->pattern}].");
        }

        $this->routes[$route->type] = $route;
    }

    public function has(string $type): bool
    {
        return isset($this->routes[$type]);
    }

    public function path(string $type, string $locale, array $params): ?string
    {
        $route = $this->routes[$type] ?? null;

        if ($route === null) {
            return null;
        }

        $values = [...$params, 'locale' => $locale];
        $missing = false;

        $path = preg_replace_callback('/\{([a-z_]+)\}/', static function (array $match) use ($values, &$missing): string {
            $value = $values[$match[1]] ?? null;

            if (! is_string($value) || $value === '') {
                $missing = true;

                return '';
            }

            return rawurlencode($value);
        }, $route->pattern);

        return $missing || ! is_string($path) ? null : $path;
    }

    public function url(string $type, string $locale, array $params): ?string
    {
        $origin = app(SiteSeoDefaultsContract::class)->publicOrigin();
        $path = $this->path($type, $locale, $params);

        return $origin === null || $path === null ? null : rtrim($origin, '/').$path;
    }
}
