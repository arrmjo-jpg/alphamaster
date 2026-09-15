<?php

declare(strict_types=1);

use App\Modules\Localization\Controllers\Admin\LanguageAdminController;
use App\Modules\Localization\Controllers\Admin\TranslationBatchController;
use App\Modules\Localization\Controllers\Admin\TranslationOverviewController;
use App\Modules\Localization\Controllers\Admin\TranslationWorkshopController;
use App\Modules\Localization\Controllers\Api\InterfaceCatalogueController;
use App\Modules\Localization\Controllers\Api\LanguageApiController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    // Public localization routes
    // Cacheable, and tagged so a change to the language set purges it from the edge
    // (ADR 0053). The tag literal is LocaleResolver::edgeTag().
    Route::get('/languages', [LanguageApiController::class, 'index'])
        ->middleware('http.cache:public-configuration,localization:languages')
        ->name('api.languages.index');

    // The console's wording in a language (ADR 0049): public, because sign-in is read first, and
    // cached at the edge under a tag every interface translation and language change purges.
    // The tag literal is InterfaceCatalogue::edgeTag().
    Route::get('/interface/console/{locale}', [InterfaceCatalogueController::class, 'console'])
        ->where('locale', '[A-Za-z0-9_-]{1,16}')
        ->middleware('http.cache:public-configuration,localization:interface')
        ->name('api.interface.console');

    // Language management. Reading the list stays behind the perimeter alone: every editor of
    // localized content — pages, team, settings, the workshop — needs every language, served or
    // not. Changing one is `languages.manage`, because a language decides what the whole platform
    // serves. The literal, not the enum, for the reason `ai.use` is one below.
    Route::prefix('admin/languages')
        ->middleware(['auth:sanctum', 'ability:admin:access', 'active', 'admin', 'email-verified'])
        ->group(function (): void {
            Route::get('/', [LanguageAdminController::class, 'index'])->name('admin.languages.index');
            Route::post('/', [LanguageAdminController::class, 'store'])
                ->middleware('permission:languages.manage')
                ->name('admin.languages.store');
            Route::get('/{id}', [LanguageAdminController::class, 'show'])->name('admin.languages.show');
            Route::put('/{id}', [LanguageAdminController::class, 'update'])
                ->middleware('permission:languages.manage')
                ->name('admin.languages.update');
            Route::patch('/{id}/status', [LanguageAdminController::class, 'toggleStatus'])
                ->middleware('permission:languages.manage')
                ->name('admin.languages.status');
            Route::patch('/{id}/default', [LanguageAdminController::class, 'setDefault'])
                ->middleware('permission:languages.manage')
                ->name('admin.languages.default');
        });

    // AI translation of items (ADR 0044, ADR 0056).
    //
    // Registered before the workshop's own group, whose `/{source}/{id}` would otherwise be
    // a candidate for any two-segment path under it.
    //
    // Asking carries `ai.use` on the route, because a request costs money on the operator's
    // account with the vendor and that is a power of its own. Which content may be translated
    // — and accepted — is the owning module's write permission, asked per source in the
    // controller: one endpoint serves several modules' content, and one middleware could only
    // ever ask one of their questions.
    //
    // The permission is a literal, not `AdminPermission::AI_USE`: Localization may not
    // import Authorization, in a route file any more than in a class, and the route-file guard
    // reads every `permission:` literal against the catalogue so a typo still fails a test.
    //
    // There is no route for a field. An item is translated, reviewed and accepted as one.
    Route::prefix('admin/translations/batches')
        ->middleware(['auth:sanctum', 'ability:admin:access', 'active', 'admin', 'email-verified'])
        ->group(function (): void {
            Route::post('/', [TranslationBatchController::class, 'store'])
                ->middleware('permission:ai.use')
                ->name('admin.translations.batches.store');

            Route::post('/accept-ready', [TranslationBatchController::class, 'acceptReady'])
                ->name('admin.translations.batches.acceptReady');

            Route::post('/{batch}/accept', [TranslationBatchController::class, 'accept'])
                ->name('admin.translations.batches.accept');

            Route::delete('/{batch}', [TranslationBatchController::class, 'destroy'])
                ->name('admin.translations.batches.destroy');
        });

    // The translation workshop: what is written in each of those languages.
    //
    // No permission on the routes, and that is not an omission. One endpoint serves
    // several bodies of content owned by different modules, so "may this caller?" has
    // a different answer per source — role labels need `roles.update`, notification
    // wording needs `notifications.update` — and a single middleware could only ever
    // ask one of those questions. The controller asks the owning module's (ADR 0043).
    Route::prefix('admin/translations')
        ->middleware(['auth:sanctum', 'ability:admin:access', 'active', 'admin', 'email-verified'])
        ->group(function (): void {
            Route::get('/', [TranslationWorkshopController::class, 'index'])
                ->name('admin.translations.index');

            // Where every language stands: coverage, AI progress and availability, in
            // one call for the Languages page (ADR 0048 §5).
            Route::get('/overview', [TranslationOverviewController::class, 'show'])
                ->name('admin.translations.overview');

            Route::put('/{source}/{id}', [TranslationWorkshopController::class, 'update'])
                ->name('admin.translations.update');
        });
});
