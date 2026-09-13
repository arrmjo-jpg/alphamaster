<?php

declare(strict_types=1);

namespace App\Modules\Core\Contracts;

use Illuminate\Http\Request;

interface LocaleResolverInterface
{
    /**
     * Resolve the active locale code for the incoming HTTP request.
     */
    public function resolve(Request $request): string;

    /**
     * Determine the text direction ('ltr' or 'rtl') for the given or active locale.
     */
    public function getDirection(?string $locale = null): string;

    /**
     * Get the list of all currently active language codes.
     *
     * @return array<int, string>
     */
    public function getActiveLanguageCodes(): array;

    /**
     * Every language code the platform knows, served or not.
     *
     * Wider than the active set on purpose: a language is translatable before it is
     * served (ADR 0048), so anything validating a *content* language — which language
     * a value is written in — asks this rather than the active list. Deciding which
     * language to answer a request in still asks that one.
     *
     * @return array<int, string>
     */
    public function getKnownLocaleCodes(): array;

    /**
     * Get the authoritative default locale code from database or fallback.
     */
    public function getDefaultLocale(): string;

    /**
     * Invalidate any cached language state in Redis.
     */
    public function clearCache(): void;
}
