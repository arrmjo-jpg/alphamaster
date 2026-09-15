<?php

declare(strict_types=1);

namespace App\Modules\Localization\Controllers\Api;

use App\Modules\Core\Contracts\LocaleResolverInterface;
use App\Modules\Core\Controllers\BaseApiController;
use App\Modules\Localization\Interface\InterfaceCatalogue;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Arr;

/**
 * The console's wording in one language (ADR 0049).
 *
 * Public, because the sign-in screen is read before anyone is signed in, and cacheable at the
 * edge, because the language is in the address. It answers for any language Language Management
 * knows: what ships for it, with what operators translated laid over. A language with nothing
 * yet answers with nothing, and the console reads it in the catalogue's own language key by key.
 */
class InterfaceCatalogueController extends BaseApiController
{
    public function __construct(
        private readonly InterfaceCatalogue $catalogue,
        private readonly LocaleResolverInterface $locales,
    ) {}

    /**
     * The console catalogue for a language, nested as the console reads it.
     */
    #[Response(200, type: 'array{success: bool, data: array<string, mixed>}')]
    #[Response(404, description: 'UNKNOWN_LOCALE: not a language Language Management knows.')]
    public function console(string $locale): JsonResponse
    {
        if ($locale !== $this->catalogue->sourceLocale()
            && ! in_array($locale, $this->locales->getKnownLocaleCodes(), true)) {
            return $this->errorResponse('UNKNOWN_LOCALE', 'api.error.translations.unknown_locale', null, 404, ['locale' => $locale]);
        }

        return $this->successResponse(
            (object) Arr::undot($this->catalogue->resolved(InterfaceCatalogue::CONSOLE, $locale))
        );
    }
}
