<?php

declare(strict_types=1);

use App\Modules\Localization\Database\Seeders\LanguageSeeder;
use App\Modules\Settings\Definitions\SettingDefinition;
use App\Modules\Settings\Definitions\SettingRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(LanguageSeeder::class);
    $this->registry = app(SettingRegistry::class);
});

// Every setting reads as words, in both languages.
//
// `SettingDefinition::label()` falls back to the identifier when no translation exists,
// which ADR 0030 asks for deliberately — an untranslated label shows something visibly
// wrong rather than an empty space. It also means a missing label is invisible to every
// other test, and for a long time every setting was missing one: the admin title-cased
// `max_upload_kilobytes` into "Max Upload Kilobytes" and no screen said what it did.

test('every declared setting resolves a label in both locales', function (): void {
    $missing = [];

    foreach (['en', 'ar'] as $locale) {
        app()->setLocale($locale);

        foreach ($this->registry->all() as $definition) {
            /** @var SettingDefinition $definition */
            if ($definition->label() === $definition->labelKey() || $definition->label() === $definition->key) {
                $missing[] = $locale.': '.$definition->reference();
            }
        }
    }

    expect($missing)->toBe([]);
});

test('every declared setting says what it does, in both locales', function (): void {
    $missing = [];

    foreach (['en', 'ar'] as $locale) {
        app()->setLocale($locale);

        foreach ($this->registry->all() as $definition) {
            /** @var SettingDefinition $definition */
            $help = $definition->help();

            if ($help === null || trim($help) === '') {
                $missing[] = $locale.': '.$definition->reference();
            }
        }
    }

    expect($missing)->toBe([]);
});

test('an Arabic label is a translation rather than the English one repeated', function (): void {
    // The failure this catches is a key copied into ar.json with its English value, which
    // resolves, satisfies the test above, and reads as an untranslated interface.
    $untranslated = [];

    foreach ($this->registry->all() as $definition) {
        /** @var SettingDefinition $definition */
        app()->setLocale('en');
        $english = $definition->label();

        app()->setLocale('ar');
        $arabic = $definition->label();

        if ($english === $arabic) {
            $untranslated[] = $definition->reference();
        }
    }

    expect($untranslated)->toBe([]);
});

test('a label carries no value, and no secret', function (): void {
    // A label describes a setting; it never restates what the setting is set to. The
    // rule matters most for the two secrets, whose values must not reach a client at
    // all — a help string quoting one would be a leak through the one part of the
    // payload nobody inspects.
    app()->setLocale('en');

    foreach ($this->registry->all() as $definition) {
        /** @var SettingDefinition $definition */
        if (! $definition->isSecret) {
            continue;
        }

        expect($definition->label())->not->toContain('=')
            ->and(strtolower($definition->help() ?? ''))->not->toContain('password is')
            ->and($definition->help())->toContain('encrypted');
    }
});
