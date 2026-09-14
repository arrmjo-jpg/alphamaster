<?php

declare(strict_types=1);

use App\Modules\Core\Translation\TranslationField;
use App\Modules\Core\Translation\TranslationRegistry;
use App\Modules\Core\Translation\TranslationSource;
use App\Modules\Pages\Translation\PageTranslationSource;
use App\Modules\Team\Translation\TeamTranslationSource;
use Symfony\Component\Finder\Finder;

/**
 * One translation contract for every module (ADR 0043, ADR 0056).
 *
 * Pages and Team are translated because they register a `TranslationSource` that describes its
 * fields, exactly as roles, settings and notification wording do — not through a translation
 * system of their own. And the translation code never learns which modules exist: it has no
 * branch on a source's key.
 */
function applicationFiles(string $directory): Finder
{
    return Finder::create()->files()->in(app_path($directory))->name('*.php');
}

test('pages and team are registered sources of the one translation registry', function (): void {
    $sources = app(TranslationRegistry::class)->all();

    expect($sources['pages'] ?? null)->toBeInstanceOf(PageTranslationSource::class)
        ->and($sources['team'] ?? null)->toBeInstanceOf(TeamTranslationSource::class)
        ->and(PageTranslationSource::class)->toImplement(TranslationSource::class)
        ->and(TeamTranslationSource::class)->toImplement(TranslationSource::class);
});

test('every source describes its fields with the shared metadata, and none offers a slug', function (): void {
    foreach (app(TranslationRegistry::class)->all() as $key => $source) {
        foreach ($source->entries() as $entry) {
            foreach ($entry->fields as $field) {
                expect($field)->toBeInstanceOf(TranslationField::class)
                    ->and($field->name)->not->toBe('slug', "{$key} offers a slug");
            }
        }
    }

    expect(true)->toBeTrue();
});

test('pages and team have no translation machinery of their own', function (): void {
    foreach (['Modules/Pages', 'Modules/Team'] as $module) {
        foreach (applicationFiles($module) as $file) {
            expect($file->getContents())
                ->not->toContain('App\\Modules\\Localization')
                ->not->toContain('TranslationSuggestion')
                ->not->toContain('TranslationBatch')
                ->not->toContain('TextGeneratorContract');
        }
    }
});

test('the translation code never branches on which module a source belongs to', function (): void {
    $keys = array_keys(app(TranslationRegistry::class)->all());

    expect($keys)->toContain('pages', 'team', 'roles', 'settings', 'notification-templates');

    foreach (['Modules/Localization', 'Modules/Core/Translation'] as $directory) {
        foreach (applicationFiles($directory) as $file) {
            foreach ($keys as $key) {
                expect($file->getContents())->not->toContain("'{$key}'", $file->getRelativePathname()." names the source '{$key}'");
            }
        }
    }
});

test('the admin API has no endpoint that names a single field', function (): void {
    $uris = collect(app('router')->getRoutes()->getRoutes())
        ->map(fn ($route): string => $route->uri())
        ->filter(fn (string $uri): bool => str_contains($uri, 'translations'))
        ->values()
        ->all();

    foreach ($uris as $uri) {
        expect($uri)->not->toContain('{field}')->not->toContain('suggestion');
    }
});
