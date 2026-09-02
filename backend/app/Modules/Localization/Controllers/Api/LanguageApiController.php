<?php

declare(strict_types=1);

namespace App\Modules\Localization\Controllers\Api;

use App\Modules\Core\Controllers\BaseApiController;
use App\Modules\Localization\Models\Language;
use App\Modules\Localization\Resources\LanguageResource;
use App\Modules\Localization\Services\LocaleResolver;
use Illuminate\Http\JsonResponse;

class LanguageApiController extends BaseApiController
{
    public function __construct(
        protected LocaleResolver $localeResolver
    ) {}

    /**
     * List all active languages for clients.
     */
    public function index(): JsonResponse
    {
        $languages = Language::query()
            ->active()
            ->ordered()
            ->get();

        return $this->successResponse(
            data: LanguageResource::collection($languages),
            meta: [
                'current_locale' => app()->getLocale(),
                'direction' => $this->localeResolver->getDirection(),
            ]
        );
    }
}
