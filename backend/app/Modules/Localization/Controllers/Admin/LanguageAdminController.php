<?php

declare(strict_types=1);

namespace App\Modules\Localization\Controllers\Admin;

use App\Modules\Core\Controllers\BaseApiController;
use App\Modules\Localization\Models\Language;
use App\Modules\Localization\Requests\StoreLanguageRequest;
use App\Modules\Localization\Requests\UpdateLanguageRequest;
use App\Modules\Localization\Resources\LanguageResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class LanguageAdminController extends BaseApiController
{
    /**
     * List all languages for administrative overview.
     */
    public function index(): JsonResponse
    {
        $languages = Language::query()
            ->ordered()
            ->get();

        return $this->successResponse(LanguageResource::collection($languages));
    }

    /**
     * Create a new language.
     */
    public function store(StoreLanguageRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $language = DB::transaction(function () use ($validated): Language {
            if (! empty($validated['is_default'])) {
                Language::query()->where('is_default', true)->update(['is_default' => false]);
                $validated['is_active'] = true; // Default language must be active
            }

            return Language::query()->create($validated);
        });

        return $this->successResponse(
            data: new LanguageResource($language),
            message: 'Language created successfully.',
            statusCode: 201
        );
    }

    /**
     * Show language details.
     */
    public function show(string $id): JsonResponse
    {
        $language = Language::query()->findOrFail($id);

        return $this->successResponse(new LanguageResource($language));
    }

    /**
     * Update an existing language.
     */
    public function update(UpdateLanguageRequest $request, string $id): JsonResponse
    {
        $language = Language::query()->findOrFail($id);
        $validated = $request->validated();

        DB::transaction(function () use ($language, $validated): void {
            if (! empty($validated['is_default']) && ! $language->is_default) {
                Language::query()->where('id', '!=', $language->id)->where('is_default', true)->update(['is_default' => false]);
                $validated['is_active'] = true;
            }

            // Cannot deactivate the default language
            if (isset($validated['is_active']) && ! $validated['is_active'] && $language->is_default) {
                unset($validated['is_active']);
            }

            $language->update($validated);
        });

        return $this->successResponse(
            data: new LanguageResource($language->fresh()),
            message: 'Language updated successfully.'
        );
    }

    /**
     * Toggle language active status.
     */
    public function toggleStatus(string $id): JsonResponse
    {
        $language = Language::query()->findOrFail($id);

        if ($language->is_default && $language->is_active) {
            return $this->errorResponse(
                code: 'CANNOT_DEACTIVATE_DEFAULT_LANGUAGE',
                message: 'The default application language cannot be deactivated.',
                statusCode: 422
            );
        }

        $language->update([
            'is_active' => ! $language->is_active,
        ]);

        return $this->successResponse(
            data: new LanguageResource($language),
            message: $language->is_active ? 'Language activated successfully.' : 'Language deactivated successfully.'
        );
    }

    /**
     * Set the specified language as the default application language.
     */
    public function setDefault(string $id): JsonResponse
    {
        $language = Language::query()->findOrFail($id);

        DB::transaction(function () use ($language): void {
            // Unset all existing defaults
            Language::query()->where('is_default', true)->update(['is_default' => false]);

            // Set new default and ensure it is active
            $language->update([
                'is_default' => true,
                'is_active' => true,
            ]);
        });

        return $this->successResponse(
            data: new LanguageResource($language->fresh()),
            message: 'Default language updated successfully.'
        );
    }
}
