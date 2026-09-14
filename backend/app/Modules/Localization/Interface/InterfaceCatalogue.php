<?php

declare(strict_types=1);

namespace App\Modules\Localization\Interface;

use App\Modules\Core\Cache\CacheNamespace;
use App\Modules\Core\Contracts\EdgeCacheContract;
use App\Modules\Core\Contracts\PlatformCacheContract;
use App\Modules\Core\Delivery\EdgeCacheTag;
use App\Modules\Core\Delivery\EdgeInvalidation;
use App\Modules\Localization\Models\InterfaceTranslation;
use Illuminate\Support\Arr;
use Throwable;

/**
 * The Interface Translation Catalog: what the platform's interface says, in every language
 * (ADR 0049).
 *
 * Two catalogues, both code:
 *
 *   * **console** — the Admin's wording, `lang/interface/console/{locale}.json`;
 *   * **api** — the API's messages, `lang/{locale}.json` and the group files in `lang/{locale}/`,
 *     addressed as `group::path` so a group line and a JSON line can never be confused.
 *
 * The catalogue's own language (`app.fallback_locale`) is the source: its files define every
 * key, so a key a developer adds is in the catalogue the moment it is deployed. Files shipped
 * for another language are that language's base. Over both lies what operators wrote in the
 * workshop, in `interface_translations`, which no deploy touches.
 *
 * Languages are not decided here. Any language Language Management knows can be resolved; one
 * with nothing shipped and nothing written resolves to nothing, and reads in the source language
 * key by key.
 */
class InterfaceCatalogue
{
    public const CONSOLE = 'console';

    public const API = 'api';

    /** How a line of a Laravel group file is addressed: `validation::custom.settings.required`. */
    public const GROUP_SEPARATOR = '::';

    private const RESOURCE = 'interface-overlay';

    /** The cache generation this process's translator last loaded lines under. */
    private ?int $translatorGeneration = null;

    public function __construct(private readonly PlatformCacheContract $cache) {}

    /**
     * @return list<string>
     */
    public function names(): array
    {
        return [self::CONSOLE, self::API];
    }

    /**
     * The language the catalogue is written in, and every other language is translated from.
     */
    public function sourceLocale(): string
    {
        return (string) config('app.fallback_locale');
    }

    /**
     * Every key of a catalogue with its source text. The catalogue's key list.
     *
     * @return array<string, string>
     */
    public function source(string $catalogue): array
    {
        return $this->shipped($catalogue, $this->sourceLocale());
    }

    /**
     * What ships in code for a language, flattened.
     *
     * @return array<string, string>
     */
    public function shipped(string $catalogue, string $locale): array
    {
        // A code is a file name here, so nothing but a code is ever read.
        if (preg_match('/^[A-Za-z0-9_-]{1,16}$/', $locale) !== 1) {
            return [];
        }

        return match ($catalogue) {
            self::CONSOLE => $this->strings(Arr::dot($this->json(lang_path("interface/console/{$locale}.json")))),
            self::API => $this->apiLines($locale),
            default => [],
        };
    }

    /**
     * What each key displays in a language: what ships for it, then what operators wrote.
     *
     * A key missing from the result is shown in the source language by whoever reads it. That is
     * display; the workshop still counts it as not translated.
     *
     * @return array<string, string>
     */
    public function resolved(string $catalogue, string $locale): array
    {
        $lines = $this->shipped($catalogue, $locale);

        foreach ($this->overlay($catalogue, $locale) as $key => $row) {
            $lines[$key] = $row['value'];
        }

        return $lines;
    }

    /**
     * What operators wrote for one language, cached. Empty when the store cannot be read — the
     * interface must still render from its files.
     *
     * @return array<string, array{value: string, hash: string}>
     */
    public function overlay(string $catalogue, string $locale): array
    {
        try {
            /** @var array<string, array{value: string, hash: string}> $rows */
            $rows = $this->cache->remember(
                CacheNamespace::LOCALIZATION,
                self::RESOURCE,
                [$catalogue, $locale],
                fn (): array => $this->query($catalogue, [$locale])[$locale] ?? [],
            );

            return $rows;
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * What operators wrote, for every language. Read directly, for the workshop.
     *
     * @return array<string, array<string, array{value: string, hash: string}>>
     */
    public function overlays(string $catalogue): array
    {
        try {
            return $this->query($catalogue, null);
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * Whether a stored translation was written against the source text as it reads now.
     */
    public static function isCurrent(string $sourceText, string $hash): bool
    {
        return hash_equals(self::hash($sourceText), $hash);
    }

    public static function hash(string $text): string
    {
        return hash('sha256', $text);
    }

    /**
     * Write operators' translations. Null or empty removes a key's translation, returning it to
     * what ships, or to the source language.
     *
     * @param  array<string, string|null>  $values
     */
    public function write(string $catalogue, string $locale, array $values, ?string $actor): void
    {
        $source = $this->source($catalogue);

        foreach ($values as $key => $value) {
            $key = (string) $key;
            $scope = ['catalogue' => $catalogue, 'locale' => $locale, 'key' => $key];

            if ($value === null || trim($value) === '') {
                InterfaceTranslation::query()->where($scope)->delete();

                continue;
            }

            InterfaceTranslation::query()->updateOrCreate($scope, [
                'value' => $value,
                'source_hash' => self::hash($source[$key] ?? ''),
                'updated_by' => $actor,
            ]);
        }

        $this->forget();
    }

    /**
     * Drop every cached copy: the application's, the edge's, and this process's translator.
     *
     * Called when a translation is written and when a language changes, so nobody restarts
     * anything to see either.
     */
    public function forget(): void
    {
        rescue(fn () => $this->cache->flushNamespace(CacheNamespace::LOCALIZATION), report: false);

        rescue(static fn () => app(EdgeCacheContract::class)->invalidate(
            EdgeInvalidation::tags([self::edgeTag()]),
            'localization.interface_changed',
        ), report: false);

        if (app()->resolved('translator')) {
            app('translator')->setLoaded([]);
        }
    }

    /**
     * A long-running worker keeps the lines its translator loaded. Before each job it reloads
     * them if the catalogue changed since, so a queued message goes out in today's wording.
     */
    public function refreshTranslator(): void
    {
        try {
            $generation = $this->cache->generation(CacheNamespace::LOCALIZATION);
        } catch (Throwable) {
            return;
        }

        if ($this->translatorGeneration !== null && $this->translatorGeneration !== $generation && app()->resolved('translator')) {
            app('translator')->setLoaded([]);
        }

        $this->translatorGeneration = $generation;
    }

    /**
     * The edge cache tag the public console catalogue carries.
     */
    public static function edgeTag(): string
    {
        return EdgeCacheTag::for('localization', 'interface');
    }

    /**
     * @param  list<string>|null  $locales
     * @return array<string, array<string, array{value: string, hash: string}>>
     */
    private function query(string $catalogue, ?array $locales): array
    {
        $rows = InterfaceTranslation::query()
            ->where('catalogue', $catalogue)
            ->when($locales !== null, fn ($query) => $query->whereIn('locale', $locales))
            ->get(['locale', 'key', 'value', 'source_hash']);

        $byLocale = [];

        foreach ($rows as $row) {
            $byLocale[$row->locale][$row->key] = ['value' => $row->value, 'hash' => $row->source_hash];
        }

        return $byLocale;
    }

    /**
     * The API's lines for a language: the JSON file, then each group file the source language
     * has, as `group::path`.
     *
     * @return array<string, string>
     */
    private function apiLines(string $locale): array
    {
        $lines = $this->strings($this->json(lang_path("{$locale}.json")));

        foreach (glob(lang_path($this->sourceLocale().'/*.php')) ?: [] as $file) {
            $group = basename($file, '.php');
            $path = lang_path("{$locale}/{$group}.php");

            if (! is_file($path)) {
                continue;
            }

            $contents = require $path;

            if (! is_array($contents)) {
                continue;
            }

            foreach ($this->strings(Arr::dot($contents)) as $key => $text) {
                $lines[$group.self::GROUP_SEPARATOR.$key] = $text;
            }
        }

        return $lines;
    }

    /**
     * @return array<array-key, mixed>
     */
    private function json(string $path): array
    {
        if (! is_file($path)) {
            return [];
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param  array<array-key, mixed>  $lines
     * @return array<string, string>
     */
    private function strings(array $lines): array
    {
        $strings = [];

        foreach ($lines as $key => $value) {
            if (is_string($value)) {
                $strings[(string) $key] = $value;
            }
        }

        return $strings;
    }
}
