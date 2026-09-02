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
     * Invalidate any cached language state in Redis.
     */
    public function clearCache(): void;
}
