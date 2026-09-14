<?php

declare(strict_types=1);

namespace App\Modules\Localization\Interface;

use Closure;
use Illuminate\Contracts\Translation\Loader;
use Illuminate\Support\Arr;
use Throwable;

/**
 * Laravel's translation loader, with operators' translations of the API catalogue laid over its
 * files (ADR 0049).
 *
 * Nothing about how messages are written changes: `__()`, validation messages and notification
 * wording still load from `lang/`, and a language with no file loads nothing and falls back
 * key by key as before. What an operator translated in the workshop is merged over whatever
 * loaded — a JSON line by its key, a group line by `group::path`.
 *
 * The catalogue is resolved lazily, so building the translator never touches the cache or the
 * database, and a store that cannot be read leaves the files' lines alone.
 */
final class OverlayTranslationLoader implements Loader
{
    /**
     * @param  Closure(): InterfaceCatalogue  $catalogue
     */
    public function __construct(
        private readonly Loader $files,
        private readonly Closure $catalogue,
    ) {}

    /**
     * @param  string  $locale
     * @param  string  $group
     * @param  string|null  $namespace
     * @return array<array-key, mixed>
     */
    public function load($locale, $group, $namespace = null)
    {
        $lines = $this->files->load($locale, $group, $namespace);

        // A package's namespaced lines are the package's.
        if ($namespace !== null && $namespace !== '*') {
            return $lines;
        }

        try {
            $overlay = ($this->catalogue)()->overlay(InterfaceCatalogue::API, (string) $locale);
        } catch (Throwable) {
            return $lines;
        }

        if ($overlay === []) {
            return $lines;
        }

        $prefix = $group.InterfaceCatalogue::GROUP_SEPARATOR;

        foreach ($overlay as $key => $row) {
            $isGroupLine = str_contains($key, InterfaceCatalogue::GROUP_SEPARATOR);

            if ($group === '*') {
                if (! $isGroupLine) {
                    $lines[$key] = $row['value'];
                }

                continue;
            }

            if (str_starts_with($key, $prefix)) {
                Arr::set($lines, substr($key, strlen($prefix)), $row['value']);
            }
        }

        return $lines;
    }

    /**
     * @param  string  $namespace
     * @param  string  $hint
     */
    public function addNamespace($namespace, $hint)
    {
        $this->files->addNamespace($namespace, $hint);
    }

    /**
     * @param  string  $path
     */
    public function addJsonPath($path)
    {
        $this->files->addJsonPath($path);
    }

    /**
     * @return array<string, string>
     */
    public function namespaces()
    {
        return $this->files->namespaces();
    }

    /**
     * Anything else the file loader offers — `addPath`, say — is still the file loader's.
     *
     * @param  array<int, mixed>  $arguments
     */
    public function __call(string $method, array $arguments): mixed
    {
        return $this->files->{$method}(...$arguments);
    }
}
