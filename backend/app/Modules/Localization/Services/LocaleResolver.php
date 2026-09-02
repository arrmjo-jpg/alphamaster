<?php

declare(strict_types=1);

namespace App\Modules\Localization\Services;

use App\Modules\Core\Contracts\LocaleResolverInterface;
use App\Modules\Localization\Models\Language;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

class LocaleResolver implements LocaleResolverInterface
{
    public const CACHE_KEY_ACTIVE = 'localization:languages:active';

    public const CACHE_KEY_DEFAULT = 'localization:languages:default';

    public const CACHE_TTL = 86400; // 24 hours

    /**
     * Resolve the active locale for an incoming request according to the deterministic precedence:
     * 1. Explicit request header (X-Locale) or query parameter (?locale=)
     * 2. Authenticated user's preferred locale (if available)
     * 3. HTTP Accept-Language header negotiation (filtering q=0, wildcards, malformed weights)
     * 4. Database-configured default language (is_default = true)
     * 5. Application configuration fallback (config('app.locale'))
     */
    public function resolve(Request $request): string
    {
        $activeLanguages = $this->getActiveLanguages();

        if ($activeLanguages->isEmpty()) {
            return (string) config('app.locale', 'en');
        }

        $activeCodes = $activeLanguages->pluck('code')->all();

        // 1. Explicit request header (X-Locale) or query parameter (?locale=)
        $explicit = $request->header('X-Locale') ?? $request->query('locale');
        if (is_string($explicit) && $explicit !== '') {
            $normalized = $this->normalizeLocale($explicit);
            if (in_array($normalized, $activeCodes, true)) {
                return $normalized;
            }
        }

        // 2. Authenticated user preference
        $user = $request->user();
        if ($user) {
            $userLocale = $user->preferred_locale ?? $user->locale ?? null;
            if (is_string($userLocale) && $userLocale !== '') {
                $normalizedUserLocale = $this->normalizeLocale($userLocale);
                if (in_array($normalizedUserLocale, $activeCodes, true)) {
                    return $normalizedUserLocale;
                }
            }
        }

        // 3. HTTP Accept-Language negotiation
        $acceptHeader = $request->header('Accept-Language');
        if (is_string($acceptHeader) && $acceptHeader !== '') {
            $matchedLocale = $this->negotiateAcceptLanguage($acceptHeader, $activeCodes);
            if ($matchedLocale !== null) {
                return $matchedLocale;
            }
        }

        // 4. Database default language
        $defaultCode = $this->getDefaultLanguageCode();
        if ($defaultCode !== null && in_array($defaultCode, $activeCodes, true)) {
            return $defaultCode;
        }

        // 5. Application configuration fallback
        $configLocale = (string) config('app.locale', 'en');
        if (in_array($configLocale, $activeCodes, true)) {
            return $configLocale;
        }

        return $activeCodes[0];
    }

    /**
     * Determine the text direction ('ltr' or 'rtl') for the given or currently active locale.
     */
    public function getDirection(?string $locale = null): string
    {
        $targetLocale = $locale !== null && $locale !== ''
            ? $this->normalizeLocale($locale)
            : app()->getLocale();

        $languages = $this->getActiveLanguages();
        $matched = $languages->firstWhere('code', $targetLocale);

        if ($matched && isset($matched['direction'])) {
            return (string) $matched['direction'];
        }

        return 'ltr';
    }

    /**
     * Get array of all currently active language codes.
     *
     * @return array<int, string>
     */
    public function getActiveLanguageCodes(): array
    {
        return $this->getActiveLanguages()->pluck('code')->all();
    }

    /**
     * Get the authoritative default locale from the database or fallback.
     */
    public function getDefaultLocale(): string
    {
        $defaultCode = $this->getDefaultLanguageCode();

        return $defaultCode ?? (string) config('app.locale', 'en');
    }

    /**
     * Retrieve active languages metadata from Redis cache or database.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function getActiveLanguages(): Collection
    {
        $cached = Cache::remember(self::CACHE_KEY_ACTIVE, self::CACHE_TTL, function (): array {
            try {
                return Language::query()
                    ->active()
                    ->ordered()
                    ->get(['id', 'code', 'name', 'native_name', 'direction', 'is_default', 'sort_order'])
                    ->map(fn (Language $lang): array => [
                        'id' => $lang->id,
                        'code' => $lang->code,
                        'name' => $lang->name,
                        'native_name' => $lang->native_name,
                        'direction' => is_object($lang->direction) ? $lang->direction->value : (string) $lang->direction,
                        'is_default' => (bool) $lang->is_default,
                        'sort_order' => (int) $lang->sort_order,
                    ])
                    ->all();
            } catch (\Throwable) {
                return [];
            }
        });

        return collect($cached);
    }

    /**
     * Retrieve the default language code from Redis cache or database.
     */
    public function getDefaultLanguageCode(): ?string
    {
        return Cache::remember(self::CACHE_KEY_DEFAULT, self::CACHE_TTL, function (): ?string {
            try {
                return Language::query()
                    ->default()
                    ->value('code');
            } catch (\Throwable) {
                return null;
            }
        });
    }

    /**
     * Invalidate cached active and default languages in Redis.
     */
    public function clearCache(): void
    {
        Cache::forget(self::CACHE_KEY_ACTIVE);
        Cache::forget(self::CACHE_KEY_DEFAULT);
    }

    /**
     * Normalize locale code (e.g. 'en-US' -> 'en' or lowercase 'en').
     */
    public function normalizeLocale(string $locale): string
    {
        $trimmed = trim($locale);
        $parts = explode('-', str_replace('_', '-', $trimmed));

        return strtolower($parts[0]);
    }

    /**
     * Negotiate Accept-Language header against active language codes.
     *
     * Rules:
     * - Discards empty tags and wildcard '*'
     * - Discards explicit q=0 (not acceptable)
     * - Rejects malformed quality values without giving them precedence
     * - Sorts valid weights in descending order
     *
     * @param  array<int, string>  $availableLocales
     */
    protected function negotiateAcceptLanguage(string $header, array $availableLocales): ?string
    {
        $localesWithWeights = [];

        foreach (explode(',', $header) as $part) {
            $subParts = explode(';', trim($part));
            $tag = trim($subParts[0]);

            // Wildcard '*' and empty tags do not bypass validation
            if ($tag === '' || $tag === '*') {
                continue;
            }

            $langCode = $this->normalizeLocale($tag);
            $weight = 1.0;

            if (isset($subParts[1])) {
                $qParts = explode('=', trim($subParts[1]));
                if (isset($qParts[0], $qParts[1]) && trim($qParts[0]) === 'q') {
                    $qValue = trim($qParts[1]);
                    if (is_numeric($qValue)) {
                        $parsedWeight = (float) $qValue;
                        // Clamp between 0.0 and 1.0
                        $weight = max(0.0, min(1.0, $parsedWeight));
                    } else {
                        $weight = 0.0; // Malformed quality value is disregarded
                    }
                }
            }

            // Exclude explicit q=0 (not acceptable according to HTTP specification)
            if ($weight <= 0.0) {
                continue;
            }

            if ($langCode !== '') {
                $localesWithWeights[$langCode] = max($localesWithWeights[$langCode] ?? 0.0, $weight);
            }
        }

        arsort($localesWithWeights);

        foreach (array_keys($localesWithWeights) as $candidate) {
            if (in_array($candidate, $availableLocales, true)) {
                return $candidate;
            }
        }

        return null;
    }
}
