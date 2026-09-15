<?php

declare(strict_types=1);

namespace App\Modules\Core\Seo;

use InvalidArgumentException;

/**
 * Where one type of content is published: a pattern of path segments with named parameters
 * (ADR 0058 §1). The language comes first, so every language has an address of its own.
 */
final readonly class PublicRoute
{
    public function __construct(
        public string $type,
        public string $pattern,
    ) {
        if (preg_match('/^[a-z][a-z0-9_-]*$/', $type) !== 1) {
            throw new InvalidArgumentException("A public route type is a lower-case key: [{$type}].");
        }

        if (! str_starts_with($pattern, '/{locale}/') || preg_match('/^(\/(\{[a-z_]+\}|[a-z0-9_-]+))+$/', $pattern) !== 1) {
            throw new InvalidArgumentException("A public route pattern starts with /{locale}/ and names its parameters: [{$pattern}].");
        }
    }
}
