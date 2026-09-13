<?php

declare(strict_types=1);

namespace App\Modules\Core\Translation;

use InvalidArgumentException;

/**
 * Every body of translatable content the platform knows about.
 *
 * One registry, filled at boot by the modules that own the content. The same shape
 * `SettingRegistry` has and for the same reason: a single source that is not a single
 * file, so a module acquires translatable content by declaring a source rather than by
 * being added to a list here.
 *
 * It lives in Core rather than in Localization, following the precedent
 * `ConfigurationPortability` set (ADR 0039). Localization serves the workshop, but
 * Settings, Authorization and Notification own the content and would have to depend on
 * Localization to register with it — and the module dependency rules say they may not.
 * Core is the one place all four can name.
 *
 * Nothing is discovered. A source is registered by a service provider, which is what
 * keeps this an extension point rather than the plugin system ADR 0033 rules out.
 */
class TranslationRegistry
{
    /** @var array<string, TranslationSource> */
    private array $sources = [];

    /**
     * A duplicate key is a programming error rather than a merge: two modules claiming
     * the same content has no correct resolution, and taking the last one would make
     * the winner depend on boot order.
     */
    public function register(TranslationSource $source): void
    {
        $key = $source->key();

        if (isset($this->sources[$key])) {
            throw new InvalidArgumentException(
                "Translation source [{$key}] is already registered; a source is declared exactly once."
            );
        }

        $this->sources[$key] = $source;
    }

    /**
     * @return array<string, TranslationSource>
     */
    public function all(): array
    {
        $sources = $this->sources;
        ksort($sources);

        return $sources;
    }

    public function find(string $key): ?TranslationSource
    {
        return $this->sources[$key] ?? null;
    }
}
