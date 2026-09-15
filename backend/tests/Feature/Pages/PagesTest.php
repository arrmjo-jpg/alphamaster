<?php

declare(strict_types=1);

use App\Modules\Authorization\Database\Seeders\AdminPermissionSeeder;
use App\Modules\Core\Models\AuditRecord;
use App\Modules\Localization\Database\Seeders\LanguageSeeder;
use App\Modules\Localization\Models\Language;
use App\Modules\Pages\Enums\PagePermission;
use App\Modules\Pages\Enums\PageStatus;
use App\Modules\Pages\Models\Page;
use App\Modules\Pages\Models\PageSlugHistory;
use App\Modules\Pages\Models\PageTranslation;
use App\Modules\Settings\Database\Seeders\SettingSeeder;
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

// Static pages (ADR 0055): one record, a translation per language from Language Management,
// read strictly in the language asked for, published only with a complete default-language
// translation.

/**
 * @param  list<string>  $permissions
 */
function pagesToken(array $permissions = []): string
{
    return tokenWithPermissions([
        PagePermission::VIEW->value,
        PagePermission::CREATE->value,
        PagePermission::UPDATE->value,
        PagePermission::PUBLISH->value,
        PagePermission::DELETE->value,
        ...$permissions,
    ]);
}

function newPage(mixed $test, string $token, int $sortOrder = 0): string
{
    return (string) $test->withToken($token)
        ->postJson('/api/v1/admin/pages', ['sort_order' => $sortOrder])
        ->assertCreated()
        ->json('data.id');
}

/**
 * @param  array<string, mixed>  $body
 */
function writePage(mixed $test, string $token, string $id, string $locale, array $body): mixed
{
    return $test->withToken($token)->putJson("/api/v1/admin/pages/{$id}/translations/{$locale}", $body);
}

function publishedPrivacyPolicy(mixed $test, string $token): string
{
    $id = newPage($test, $token);

    writePage($test, $token, $id, 'en', ['title' => 'Privacy policy', 'slug' => 'privacy', 'body' => '<p>We keep little.</p>'])->assertOk();
    $test->withToken($token)->postJson("/api/v1/admin/pages/{$id}/publish")->assertOk();

    return $id;
}

// ── Writing ──────────────────────────────────────────────────────────────────

test('a page starts as a draft with no text, and every known language is not translated', function (): void {
    $token = pagesToken();

    $response = $this->withToken($token)->postJson('/api/v1/admin/pages', ['sort_order' => 1])->assertCreated();

    expect($response->json('data.status'))->toBe('draft')
        ->and($response->json('data.translations'))->toBe([])
        ->and($response->json('data.progress.en'))->toBe(['filled' => 0, 'total' => 4, 'complete' => false])
        ->and($response->json('data.progress.ar'))->toBe(['filled' => 0, 'total' => 4, 'complete' => false])
        ->and($response->json('data.publishable'))->toBeFalse();
});

test('a translation lands in the language named in the path, whatever the console speaks, with a slug made from its title', function (): void {
    $token = pagesToken();
    $id = newPage($this, $token);

    $this->withToken($token)
        ->withHeader('X-Locale', 'en')
        ->putJson("/api/v1/admin/pages/{$id}/translations/ar", ['title' => 'سياسة الخصوصية', 'body' => '<p>نص</p>'])
        ->assertOk()
        ->assertJsonPath('data.translations.ar.slug', 'سياسة-الخصوصية')
        ->assertJsonPath('data.progress.ar.complete', true);

    expect(PageTranslation::query()->where('page_id', $id)->pluck('locale')->all())->toBe(['ar']);
});

test('a language added in Language Management can be written at once, with no migration', function (): void {
    $token = pagesToken();
    $id = newPage($this, $token);

    Language::query()->create(['code' => 'fr', 'name' => 'French', 'native_name' => 'Français', 'direction' => 'ltr', 'is_active' => false, 'is_default' => false, 'sort_order' => 5]);
    Cache::flush();

    writePage($this, $token, $id, 'fr', ['title' => 'Politique de confidentialité', 'body' => '<p>Texte</p>'])
        ->assertOk()
        ->assertJsonPath('data.progress.fr.complete', true)
        ->assertJsonPath('data.translations.fr.slug', 'politique-de-confidentialité');
});

test('a language the platform does not know is refused, and the message names it', function (): void {
    $token = pagesToken();
    $id = newPage($this, $token);

    writePage($this, $token, $id, 'xx', ['title' => 'Nope'])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'UNKNOWN_CONTENT_LOCALE')
        ->assertJsonPath('error.message', 'xx is not a language the platform knows.');
});

test('a body is sanitised on write', function (): void {
    $token = pagesToken();
    $id = newPage($this, $token);

    writePage($this, $token, $id, 'en', [
        'title' => 'About',
        'body' => '<p onclick="steal()">Hello <a href="javascript:alert(1)">there</a></p><script>alert(1)</script>',
    ])->assertOk();

    $body = (string) PageTranslation::query()->where('page_id', $id)->value('body');

    expect($body)->toContain('Hello')
        ->not->toContain('<script')
        ->not->toContain('onclick')
        ->not->toContain('javascript:');
});

test('a slug an editor types must be well formed and unused in its language, and may repeat across languages', function (): void {
    $token = pagesToken();
    $first = newPage($this, $token);
    $second = newPage($this, $token);

    writePage($this, $token, $first, 'en', ['title' => 'Terms', 'slug' => 'Bad Slug'])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'CONTENT_SLUG_INVALID');

    writePage($this, $token, $first, 'en', ['title' => 'Terms', 'slug' => 'terms'])->assertOk();

    writePage($this, $token, $second, 'en', ['title' => 'Other terms', 'slug' => 'terms'])
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'CONTENT_SLUG_TAKEN');

    writePage($this, $token, $second, 'ar', ['title' => 'الشروط', 'slug' => 'terms'])->assertOk();
});

// ── Publishing ───────────────────────────────────────────────────────────────

test('publishing needs a complete default-language translation, and nothing else', function (): void {
    $token = pagesToken();
    $id = newPage($this, $token);

    writePage($this, $token, $id, 'ar', ['title' => 'الخصوصية', 'body' => '<p>نص</p>'])->assertOk();

    $this->withToken($token)->postJson("/api/v1/admin/pages/{$id}/publish")
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'CONTENT_DEFAULT_TRANSLATION_INCOMPLETE');

    writePage($this, $token, $id, 'en', ['title' => 'Privacy', 'body' => '<p>Text</p>'])->assertOk();

    $this->withToken($token)->postJson("/api/v1/admin/pages/{$id}/publish")
        ->assertOk()
        ->assertJsonPath('data.status', 'published');

    // Removing another language's text never unpublishes it.
    writePage($this, $token, $id, 'ar', ['title' => null, 'body' => null])->assertOk();

    expect(Page::query()->find($id)?->status)->toBe(PageStatus::PUBLISHED);
});

test('a published page cannot lose its default-language translation', function (): void {
    $token = pagesToken();
    $id = publishedPrivacyPolicy($this, $token);

    writePage($this, $token, $id, 'en', ['body' => null])
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'CONTENT_DEFAULT_TRANSLATION_REQUIRED');
});

test('writing and publishing are separate permissions', function (): void {
    $writer = tokenWithPermissions([PagePermission::VIEW->value, PagePermission::CREATE->value, PagePermission::UPDATE->value]);
    $id = newPage($this, $writer);

    writePage($this, $writer, $id, 'en', ['title' => 'About', 'body' => '<p>Us</p>'])->assertOk();
    $this->withToken($writer)->postJson("/api/v1/admin/pages/{$id}/publish")->assertForbidden();

    resetClient($this);
    $reader = tokenWithPermissions([PagePermission::VIEW->value]);

    writePage($this, $reader, $id, 'en', ['title' => 'Changed'])->assertForbidden();
});

test('a translation change is audited by field name, never by its text', function (): void {
    $token = pagesToken();
    $id = newPage($this, $token);

    AuditRecord::query()->getQuery()->delete();

    writePage($this, $token, $id, 'ar', ['title' => 'نص سرّي', 'body' => '<p>محتوى</p>'])->assertOk();

    $record = AuditRecord::query()->where('action', 'page.translation_updated')->sole();

    expect($record->context['locale'])->toBe('ar')
        ->and($record->context['fields'])->toEqualCanonicalizing(['title', 'slug', 'body'])
        ->and(json_encode($record->context))->not->toContain('سرّي');
});

test('deleting a page removes its translations and old addresses', function (): void {
    $token = pagesToken();
    $id = publishedPrivacyPolicy($this, $token);

    writePage($this, $token, $id, 'en', ['slug' => 'privacy-policy'])->assertOk();

    $this->withToken($token)->deleteJson("/api/v1/admin/pages/{$id}")->assertOk();

    expect(PageTranslation::query()->where('page_id', $id)->count())->toBe(0)
        ->and(PageSlugHistory::query()->where('page_id', $id)->count())->toBe(0);
});

// ── Reading, strictly in one language ────────────────────────────────────────

test('the public API needs the language, and never takes it from the console’s headers', function (): void {
    $token = pagesToken();
    publishedPrivacyPolicy($this, $token);
    resetClient($this);

    $this->getJson('/api/v1/pages')->assertStatus(422);

    $this->withHeader('X-Locale', 'ar')
        ->getJson('/api/v1/pages/privacy?locale=en')
        ->assertOk()
        ->assertJsonPath('data.locale', 'en')
        ->assertJsonPath('data.title', 'Privacy policy');
});

test('a page is public in a language only where it is complete, and nothing is substituted', function (): void {
    $token = pagesToken();
    publishedPrivacyPolicy($this, $token);
    resetClient($this);

    expect($this->getJson('/api/v1/pages?locale=ar')->assertOk()->json('data'))->toBe([])
        ->and(array_column($this->getJson('/api/v1/pages?locale=en')->assertOk()->json('data'), 'slug'))->toBe(['privacy']);

    $this->getJson('/api/v1/pages/privacy?locale=ar')
        ->assertNotFound()
        ->assertJsonPath('error.code', 'CONTENT_NOT_AVAILABLE_IN_LOCALE')
        ->assertJsonPath('error.details.available_locales', ['en']);
});

test('a language that is not served is never served, however complete', function (): void {
    $token = pagesToken();
    $id = publishedPrivacyPolicy($this, $token);

    Language::query()->create(['code' => 'fr', 'name' => 'French', 'native_name' => 'Français', 'direction' => 'ltr', 'is_active' => false, 'is_default' => false, 'sort_order' => 5]);
    Cache::flush();

    writePage($this, $token, $id, 'fr', ['title' => 'Confidentialité', 'body' => '<p>Texte</p>'])->assertOk();
    resetClient($this);

    $this->getJson('/api/v1/pages?locale=fr')
        ->assertNotFound()
        ->assertJsonPath('error.code', 'CONTENT_LOCALE_NOT_SERVED')
        ->assertJsonPath('error.message', 'fr is not served.');
});

test('another language’s address, or an old one, answers with this language’s current address', function (): void {
    $token = pagesToken();
    $id = publishedPrivacyPolicy($this, $token);

    writePage($this, $token, $id, 'ar', ['title' => 'الخصوصية', 'body' => '<p>نص</p>'])->assertOk();
    writePage($this, $token, $id, 'en', ['slug' => 'privacy-policy'])->assertOk();
    resetClient($this);

    $this->getJson('/api/v1/pages/privacy?locale=en')
        ->assertStatus(301)
        ->assertJsonPath('data.redirect.slug', 'privacy-policy');

    $this->getJson('/api/v1/pages/privacy-policy?locale=ar')
        ->assertStatus(301)
        ->assertJsonPath('data.redirect.slug', 'الخصوصية');

    $page = $this->getJson('/api/v1/pages/privacy-policy?locale=en')->assertOk();

    // No public origin is configured here, so the alternate has no absolute address (ADR 0058 §1).
    expect($page->json('data.alternates'))->toBe([['locale' => 'ar', 'slug' => 'الخصوصية', 'url' => null]]);
});

test('SEO resolves within the language asked for, never from another', function (): void {
    $token = pagesToken();
    $id = publishedPrivacyPolicy($this, $token);

    writePage($this, $token, $id, 'en', ['seo' => ['title' => 'Privacy | AlphaMaster']])->assertOk();
    writePage($this, $token, $id, 'ar', ['title' => 'الخصوصية', 'summary' => 'ما نحفظه', 'body' => '<p>نص</p>'])->assertOk();
    resetClient($this);

    expect($this->getJson('/api/v1/pages/privacy?locale=en')->json('data.seo.title'))->toBe('Privacy | AlphaMaster')
        ->and($this->getJson('/api/v1/pages/الخصوصية?locale=ar')->json('data.seo'))->toMatchArray([
            'title' => 'الخصوصية',
            'description' => 'ما نحفظه',
        ]);
});

test('the administration routes are behind the perimeter', function (): void {
    $this->getJson('/api/v1/admin/pages')->assertUnauthorized();
    $this->postJson('/api/v1/admin/pages')->assertUnauthorized();
});
