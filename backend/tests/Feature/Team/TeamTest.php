<?php

declare(strict_types=1);

use App\Modules\Authorization\Database\Seeders\AdminPermissionSeeder;
use App\Modules\Localization\Database\Seeders\LanguageSeeder;
use App\Modules\Localization\Models\Language;
use App\Modules\Settings\Database\Seeders\SettingSeeder;
use App\Modules\Team\Enums\TeamPermission;
use App\Modules\Team\Models\TeamMemberSlugHistory;
use App\Modules\Team\Models\TeamMemberTranslation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Cache::flush();

    $this->seed(SettingSeeder::class);
    $this->seed(LanguageSeeder::class);
    $this->seed(AdminPermissionSeeder::class);

    Language::query()->update(['is_default' => false]);
    Language::query()->where('code', 'en')->update(['is_default' => true, 'is_active' => true]);
    Cache::flush();
});

// The team directory (ADR 0055): one member, a profile per language, public only when the
// member is active and the profile in that language is complete.

function teamToken(): string
{
    return tokenWithPermissions([
        TeamPermission::VIEW->value,
        TeamPermission::CREATE->value,
        TeamPermission::UPDATE->value,
        TeamPermission::DELETE->value,
    ]);
}

/**
 * @param  array<string, mixed>  $attributes
 */
function newMember(mixed $test, string $token, array $attributes = []): string
{
    return (string) $test->withToken($token)
        ->postJson('/api/v1/admin/team', $attributes)
        ->assertCreated()
        ->json('data.id');
}

/**
 * @param  array<string, mixed>  $body
 */
function writeProfile(mixed $test, string $token, string $id, string $locale, array $body): mixed
{
    return $test->withToken($token)->putJson("/api/v1/admin/team/{$id}/translations/{$locale}", $body);
}

function activeEditor(mixed $test, string $token): string
{
    $id = newMember($test, $token, ['social_links' => ['website' => 'https://nadia.example.test']]);

    writeProfile($test, $token, $id, 'en', ['name' => 'Nadia Haddad', 'position' => 'Editor', 'bio' => '<p>Writes things.</p>'])->assertOk();
    $test->withToken($token)->patchJson("/api/v1/admin/team/{$id}", ['is_active' => true])->assertOk();

    return $id;
}

test('a member starts inactive with no profile, and every known language is not translated', function (): void {
    $response = $this->withToken(teamToken())->postJson('/api/v1/admin/team', [])->assertCreated();

    expect($response->json('data.is_active'))->toBeFalse()
        ->and($response->json('data.translations'))->toBe([])
        ->and($response->json('data.progress.ar'))->toBe(['filled' => 0, 'total' => 4, 'complete' => false])
        ->and($response->json('data.activatable'))->toBeFalse();
});

test('a profile lands in the language named in the path, with a slug made from the name', function (): void {
    $token = teamToken();
    $id = newMember($this, $token);

    $this->withToken($token)
        ->withHeader('X-Locale', 'en')
        ->putJson("/api/v1/admin/team/{$id}/translations/ar", ['name' => 'نادية حداد', 'position' => 'محرّرة'])
        ->assertOk()
        ->assertJsonPath('data.translations.ar.slug', 'نادية-حداد')
        ->assertJsonPath('data.progress.ar.complete', true);

    expect(TeamMemberTranslation::query()->where('team_member_id', $id)->pluck('locale')->all())->toBe(['ar']);
});

test('activating needs a complete default-language profile', function (): void {
    $token = teamToken();
    $id = newMember($this, $token);

    writeProfile($this, $token, $id, 'ar', ['name' => 'نادية', 'position' => 'محرّرة'])->assertOk();

    $this->withToken($token)->patchJson("/api/v1/admin/team/{$id}", ['is_active' => true])
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'CONTENT_DEFAULT_TRANSLATION_INCOMPLETE');

    writeProfile($this, $token, $id, 'en', ['name' => 'Nadia', 'position' => 'Editor'])->assertOk();

    $this->withToken($token)->patchJson("/api/v1/admin/team/{$id}", ['is_active' => true])
        ->assertOk()
        ->assertJsonPath('data.is_active', true);
});

test('a biography is sanitised on write, and social links must be web addresses', function (): void {
    $token = teamToken();
    $id = newMember($this, $token);

    writeProfile($this, $token, $id, 'en', ['name' => 'Nadia', 'position' => 'Editor', 'bio' => '<p>Hi</p><script>x()</script>'])->assertOk();

    expect((string) TeamMemberTranslation::query()->where('team_member_id', $id)->value('bio'))->not->toContain('<script');

    $this->withToken($token)->patchJson("/api/v1/admin/team/{$id}", ['social_links' => ['x' => 'javascript:alert(1)']])
        ->assertStatus(422);

    $this->withToken($token)->patchJson("/api/v1/admin/team/{$id}", ['social_links' => ['myspace' => 'https://example.test']])
        ->assertStatus(422);
});

test('a language added in Language Management is a profile language at once', function (): void {
    $token = teamToken();
    $id = newMember($this, $token);

    Language::query()->create(['code' => 'fr', 'name' => 'French', 'native_name' => 'Français', 'direction' => 'ltr', 'is_active' => false, 'is_default' => false, 'sort_order' => 5]);
    Cache::flush();

    writeProfile($this, $token, $id, 'fr', ['name' => 'Nadia Haddad', 'position' => 'Rédactrice'])
        ->assertOk()
        ->assertJsonPath('data.progress.fr.complete', true);

    writeProfile($this, $token, $id, 'xx', ['name' => 'Nope'])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'UNKNOWN_CONTENT_LOCALE');
});

test('the public directory lists active members complete in the language, and nothing is substituted', function (): void {
    $token = teamToken();
    activeEditor($this, $token);
    newMember($this, $token);
    resetClient($this);

    $this->getJson('/api/v1/team')->assertStatus(422);

    expect(array_column($this->getJson('/api/v1/team?locale=en')->assertOk()->json('data'), 'name'))->toBe(['Nadia Haddad'])
        ->and($this->getJson('/api/v1/team?locale=ar')->assertOk()->json('data'))->toBe([]);

    $this->getJson('/api/v1/team/nadia-haddad?locale=ar')
        ->assertNotFound()
        ->assertJsonPath('error.code', 'CONTENT_NOT_AVAILABLE_IN_LOCALE')
        ->assertJsonPath('error.details.available_locales', ['en']);
});

test('a profile is read in the language asked for, with its biography, links and SEO from that language', function (): void {
    $token = teamToken();
    $id = activeEditor($this, $token);

    writeProfile($this, $token, $id, 'ar', ['name' => 'نادية حداد', 'position' => 'محرّرة'])->assertOk();
    resetClient($this);

    $profile = $this->withHeader('X-Locale', 'ar')->getJson('/api/v1/team/nadia-haddad?locale=en')->assertOk();

    expect($profile->json('data'))->toMatchArray([
        'locale' => 'en',
        'name' => 'Nadia Haddad',
        'position' => 'Editor',
        'social_links' => ['website' => 'https://nadia.example.test'],
    ])
        ->and($profile->json('data.bio'))->toContain('Writes things.')
        ->and($profile->json('data.seo.title'))->toBe('Nadia Haddad')
        ->and($profile->json('data.seo.description'))->toBe('Editor')
        ->and($profile->json('data.alternates'))->toBe([['locale' => 'ar', 'slug' => 'نادية-حداد']]);

    $this->getJson('/api/v1/team/nadia-haddad?locale=ar')
        ->assertStatus(301)
        ->assertJsonPath('data.redirect.slug', 'نادية-حداد');
});

test('reading the directory in the Admin is not permission to change it', function (): void {
    $token = teamToken();
    $id = newMember($this, $token);
    resetClient($this);

    $reader = tokenWithPermissions([TeamPermission::VIEW->value]);

    $this->withToken($reader)->getJson('/api/v1/admin/team')->assertOk();
    writeProfile($this, $reader, $id, 'en', ['name' => 'Changed'])->assertForbidden();
    $this->withToken($reader)->deleteJson("/api/v1/admin/team/{$id}")->assertForbidden();
});

test('the team administration routes are behind the perimeter', function (): void {
    $this->getJson('/api/v1/admin/team')->assertUnauthorized();
});

// ── Addresses, order, lifecycle ──────────────────────────────────────────────

test('a renamed address answers with the new one in the same language, and nothing else', function (): void {
    $token = teamToken();
    $id = activeEditor($this, $token);

    writeProfile($this, $token, $id, 'en', ['slug' => 'nadia-h'])->assertOk();
    resetClient($this);

    $this->getJson('/api/v1/team/nadia-haddad?locale=en')
        ->assertStatus(301)
        ->assertHeader('Location', '/api/v1/team/nadia-h?locale=en')
        ->assertJsonPath('data.redirect.slug', 'nadia-h');

    $this->getJson('/api/v1/team/nadia-h?locale=en')->assertOk()->assertJsonPath('data.name', 'Nadia Haddad');
    $this->getJson('/api/v1/team/nobody-at-all?locale=en')->assertNotFound()->assertJsonPath('error.code', 'NOT_FOUND');
});

test('the public directory follows the sort order an editor sets', function (): void {
    $token = teamToken();
    $first = activeEditor($this, $token);
    $second = newMember($this, $token, ['sort_order' => 5]);

    writeProfile($this, $token, $second, 'en', ['name' => 'Omar Saleh', 'position' => 'Designer'])->assertOk();
    $this->withToken($token)->patchJson("/api/v1/admin/team/{$second}", ['is_active' => true])->assertOk();
    resetClient($this);

    expect(array_column($this->getJson('/api/v1/team?locale=en')->json('data'), 'name'))->toBe(['Nadia Haddad', 'Omar Saleh']);

    $this->withToken($token)->patchJson("/api/v1/admin/team/{$first}", ['sort_order' => 9])->assertOk();
    resetClient($this);

    expect(array_column($this->getJson('/api/v1/team?locale=en')->json('data'), 'name'))->toBe(['Omar Saleh', 'Nadia Haddad']);
});

test('a deactivated member leaves the public directory and their address', function (): void {
    $token = teamToken();
    $id = activeEditor($this, $token);

    $this->withToken($token)->patchJson("/api/v1/admin/team/{$id}", ['is_active' => false])->assertOk();
    resetClient($this);

    expect($this->getJson('/api/v1/team?locale=en')->assertOk()->json('data'))->toBe([]);
    $this->getJson('/api/v1/team/nadia-haddad?locale=en')->assertNotFound();
});

test('removing a member removes every profile and old address, and the public read with them', function (): void {
    $token = teamToken();
    $id = activeEditor($this, $token);
    writeProfile($this, $token, $id, 'en', ['slug' => 'nadia-h'])->assertOk();

    $this->withToken($token)->deleteJson("/api/v1/admin/team/{$id}")->assertOk();
    resetClient($this);

    expect(TeamMemberTranslation::query()->where('team_member_id', $id)->count())->toBe(0)
        ->and(TeamMemberSlugHistory::query()->where('team_member_id', $id)->count())->toBe(0);

    $this->getJson('/api/v1/team/nadia-h?locale=en')->assertNotFound();
    $this->getJson('/api/v1/team/nadia-haddad?locale=en')->assertNotFound();
});

test('a language the platform does not serve is refused, never substituted', function (): void {
    $token = teamToken();
    activeEditor($this, $token);
    resetClient($this);

    $this->getJson('/api/v1/team?locale=fr')->assertNotFound()->assertJsonPath('error.code', 'CONTENT_LOCALE_NOT_SERVED');
    $this->getJson('/api/v1/team/nadia-haddad?locale=fr')->assertNotFound()->assertJsonPath('error.code', 'CONTENT_LOCALE_NOT_SERVED');
});
