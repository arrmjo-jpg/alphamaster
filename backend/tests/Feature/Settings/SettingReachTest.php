<?php

declare(strict_types=1);

use App\Modules\Authorization\Database\Seeders\AdminPermissionSeeder;
use App\Modules\Localization\Database\Seeders\LanguageSeeder;
use App\Modules\Settings\Database\Seeders\SettingSeeder;
use App\Modules\Settings\Definitions\SettingDefinition;
use App\Modules\Settings\Definitions\SettingRegistry;
use App\Modules\Settings\Enums\SettingReach;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Cache::flush();
    $this->seed(SettingSeeder::class);
    $this->seed(LanguageSeeder::class);
    $this->seed(AdminPermissionSeeder::class);
});

// What changing a setting actually does.
//
// An audit of the catalogue found that a third of it changed nothing anywhere: values
// declared for a public website that has not been built, or ahead of a capability the
// platform does not have. A screen that presents those beside the setting which closes
// an account after five failed sign-ins is lying by omission, because an operator
// reasonably assumes a control that can be changed does something.
//
// Every definition now declares its reach. This file is what stops that from decaying:
// the list of settings nothing reads is written down, so adding one is a deliberate act
// that shows up in a diff rather than a thing that happens by not noticing.

/**
 * Every reference whose reach is not PLATFORM, with the reason it is not.
 *
 * Ordered by what is missing rather than by name, because that is how the list will be
 * worked through: a capability arrives, and everything waiting on it moves at once.
 *
 * @return array<string, string>
 */
function settingsNothingReadsYet(): array
{
    return [
        // ── The public website (not started; out of scope for M3) ──
        'general.site_description' => 'public site',
        'general.official_email' => 'public site',
        'general.contact_phones' => 'public site',
        'general.contact_person' => 'public site',
        'general.contact_job_title' => 'public site',
        'general.location_latitude' => 'public site',
        'general.location_longitude' => 'public site',
        'general.site_url' => 'public site',
        'general.frontend_url' => 'public site',
        'general.admin_url' => 'public site',
        'general.footer_copyright' => 'public site',
        'general.footer_text' => 'public site',
        'general.cookie_message' => 'public site',
        'general.comments_enabled' => 'public site',
        'branding.favicon' => 'public site',
        'branding.logo_light' => 'public site',
        'branding.logo_dark' => 'public site',
        'branding.logo_light_en' => 'public site',
        'branding.logo_dark_en' => 'public site',
        'branding.og_image' => 'public site',

        // ── The image pipeline (ADR 0024, deferred by ADR 0029 item 17) ──
        'branding.max_image_dimension' => 'image pipeline',
        'branding.watermark_enabled' => 'image pipeline',
        'branding.watermark_image' => 'image pipeline',
        'branding.watermark_position' => 'image pipeline',
        'branding.watermark_opacity' => 'image pipeline',
        'branding.watermark_width_percent' => 'image pipeline',
        'branding.watermark_margin_percent' => 'image pipeline',

        // ── Decided and unbuilt ──
        'auth.registration_enabled' => 'public registration',
        // A grep for a key name finds what reads a setting, and a secret's value is
        // never read by name — so this one looked used because it was mentioned.
        // Nothing authenticates with it: no header check, no middleware, no caller.
        'security.api_secret_key' => 'machine-to-machine authentication',

        // ── Read by a client rather than by the platform ──
        'localization.timezone' => 'client formatting',
        'localization.date_format' => 'client formatting',
    ];
}

test('the settings nothing reads yet are exactly the ones written down', function (): void {
    $registry = app(SettingRegistry::class);

    $declared = [];

    foreach ($registry->all() as $reference => $definition) {
        if ($definition->reach !== SettingReach::PLATFORM) {
            $declared[] = $reference;
        }
    }

    sort($declared);
    $expected = array_keys(settingsNothingReadsYet());
    sort($expected);

    // Both directions matter. A setting appearing here that is not in the list means
    // one was added without anyone deciding what reads it; a setting in the list that
    // no longer appears means a capability arrived and the list was not trimmed.
    expect($declared)->toBe($expected);
});

test('a setting nothing reads yet says so, in a sentence a person can read', function (): void {
    $registry = app(SettingRegistry::class);

    foreach (array_keys(settingsNothingReadsYet()) as $reference) {
        $definition = $registry->get($reference);
        $notice = $definition->reachNotice();

        expect($notice)->not->toBeNull($reference.' has no notice')
            // The key itself coming back means the sentence was never written, which is
            // worse than no notice at all: it reads as a bug to whoever sees it.
            ->and($notice)->not->toBe($definition->reach->noticeKey(), $reference.' notice is untranslated');
    }
});

test('a setting the platform reads carries no notice', function (): void {
    $registry = app(SettingRegistry::class);

    foreach ($registry->all() as $reference => $definition) {
        if ($definition->reach === SettingReach::PLATFORM) {
            // Saying "this one works" beside every working control would make the
            // notice noise rather than information.
            expect($definition->reachNotice())->toBeNull($reference);
        }
    }
});

test('the notice is written in both languages', function (): void {
    /** @var array<string, string> $en */
    $en = json_decode((string) file_get_contents(base_path('lang/en.json')), true);
    /** @var array<string, string> $ar */
    $ar = json_decode((string) file_get_contents(base_path('lang/ar.json')), true);

    foreach (SettingReach::cases() as $reach) {
        $key = $reach->noticeKey();

        if ($key === null) {
            continue;
        }

        expect($en)->toHaveKey($key)
            ->and($ar)->toHaveKey($key)
            ->and($ar[$key])->not->toBe($en[$key], $key.' is untranslated');
    }
});

test('the catalogue publishes the reach, so a screen can render it', function (): void {
    $token = tokenWithPermissions(['settings.view']);

    $response = $this->withToken($token)->getJson('/api/v1/admin/settings/definitions')->assertOk();

    /** @var array<int, array<string, mixed>> $general */
    $general = $response->json('data.general');

    $footer = collect($general)->firstWhere('key', 'general.footer_text');
    $maintenance = collect($general)->firstWhere('key', 'general.maintenance_mode');

    expect($footer['reach'])->toBe('awaiting')
        ->and($footer['reach_notice'])->not->toBeNull()
        // And the contrast, in the same group: the one that closes the platform to
        // callers is read the moment it changes.
        ->and($maintenance['reach'])->toBe('platform')
        ->and($maintenance['reach_notice'])->toBeNull();
});

test('every definition declares a reach, including any added later', function (): void {
    $registry = app(SettingRegistry::class);

    foreach ($registry->all() as $reference => $definition) {
        expect($definition)->toBeInstanceOf(SettingDefinition::class)
            ->and(SettingReach::tryFrom($definition->reach->value))->not->toBeNull($reference);
    }
});
