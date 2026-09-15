<?php

declare(strict_types=1);

use App\Modules\Authorization\Database\Seeders\AdminPermissionSeeder;
use App\Modules\Core\Contracts\EdgeCacheContract;
use App\Modules\Core\Delivery\EdgeCacheTag;
use App\Modules\Core\Delivery\EdgeInvalidation;
use App\Modules\Core\Delivery\EdgeInvalidationKind;
use App\Modules\Core\Delivery\EdgeInvalidationReceipt;
use App\Modules\Core\Seo\SeoMeta;
use App\Modules\Localization\Database\Seeders\LanguageSeeder;
use App\Modules\Localization\Models\Language;
use App\Modules\Media\Enums\MediaStatus;
use App\Modules\Media\Enums\MediaType;
use App\Modules\Media\Enums\MediaVisibility;
use App\Modules\Media\Enums\ScanStatus;
use App\Modules\Media\Models\MediaFile;
use App\Modules\Pages\Enums\PagePermission;
use App\Modules\Settings\Database\Seeders\SettingSeeder;
use App\Modules\Team\Enums\TeamPermission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;

/*
 * SEO through the content that uses it (ADR 0032, ADR 0055 §7, §10): every field written through
 * the Admin, resolved in the public read in its own language only, never public for a draft,
 * purged from the edge when it changes, and gone when its content is.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Cache::flush();

    $this->seed(SettingSeeder::class);
    $this->seed(LanguageSeeder::class);
    $this->seed(AdminPermissionSeeder::class);

    Language::query()->update(['is_default' => false]);
    Language::query()->where('code', 'en')->update(['is_default' => true, 'is_active' => true]);
    Language::query()->where('code', 'ar')->update(['is_active' => true]);
    Cache::flush();

    // What the edge was told to forget, recorded instead of sent.
    $this->edge = new class implements EdgeCacheContract
    {
        /** @var list<string> */
        public array $tags = [];

        public function invalidate(EdgeInvalidation $invalidation, ?string $reason = null): EdgeInvalidationReceipt
        {
            if ($invalidation->kind === EdgeInvalidationKind::TAGS) {
                array_push($this->tags, ...$invalidation->items);
            }

            return EdgeInvalidationReceipt::notConfigured();
        }

        public function tagHeader(): ?string
        {
            return null;
        }
    };

    app()->instance(EdgeCacheContract::class, $this->edge);
});

function seoContentImage(): string
{
    $file = new MediaFile;
    $file->forceFill([
        'collection' => 'content',
        'disk' => 'local',
        'path' => 'media/'.uniqid('seo_content_', true).'.png',
        'original_filename' => 'share.png',
        'mime_type' => 'image/png',
        'extension' => 'png',
        'type' => MediaType::IMAGE,
        'size_bytes' => 68,
        'checksum' => str_repeat('c', 64),
        'visibility' => MediaVisibility::PUBLIC,
        'status' => MediaStatus::READY,
        'scan_status' => ScanStatus::NOT_SCANNED,
        'width' => 1200,
        'height' => 630,
    ])->save();

    return $file->id;
}

function seoPagesToken(): string
{
    return tokenWithPermissions([
        PagePermission::VIEW->value, PagePermission::CREATE->value, PagePermission::UPDATE->value,
        PagePermission::PUBLISH->value, PagePermission::DELETE->value,
    ]);
}

function seoPublishedPage(mixed $test, string $token): string
{
    $id = (string) $test->withToken($token)->postJson('/api/v1/admin/pages', ['sort_order' => 0])->assertCreated()->json('data.id');

    $test->withToken($token)->putJson("/api/v1/admin/pages/{$id}/translations/en", [
        'title' => 'About us', 'slug' => 'about', 'summary' => 'Who we are', 'body' => '<p>We are.</p>',
    ])->assertOk();
    $test->withToken($token)->postJson("/api/v1/admin/pages/{$id}/publish")->assertOk();

    return $id;
}

test('every SEO field is written through the Admin and resolved in the public page', function (): void {
    $token = seoPagesToken();
    $id = seoPublishedPage($this, $token);
    $image = seoContentImage();

    $seo = [
        'title' => 'About | AlphaMaster',
        'description' => 'The people and the purpose.',
        'robots' => 'noindex,follow',
        'canonical_url' => 'https://www.example.test/en/about',
        'og_title' => 'Meet us',
        'og_description' => 'Shared description',
        'og_media_id' => $image,
    ];

    $this->withToken($token)->putJson("/api/v1/admin/pages/{$id}/translations/en", ['seo' => $seo])
        ->assertOk()
        ->assertJsonPath('data.seo.en', $seo);

    resetClient($this);
    $public = $this->getJson('/api/v1/pages/about?locale=en')->assertOk()->json('data.seo');

    expect($public)->toMatchArray([
        'title' => 'About | AlphaMaster',
        'description' => 'The people and the purpose.',
        'robots' => 'noindex,follow',
        'canonical_url' => 'https://www.example.test/en/about',
        'og_title' => 'Meet us',
        'og_description' => 'Shared description',
    ])->and($public['og_image_url'])->toBeString();
});

test('SEO set in one language never appears in another, including canonical and robots', function (): void {
    $token = seoPagesToken();
    $id = seoPublishedPage($this, $token);

    $this->withToken($token)->putJson("/api/v1/admin/pages/{$id}/translations/en", ['seo' => [
        'canonical_url' => 'https://www.example.test/en/about', 'robots' => 'noindex,nofollow', 'og_title' => 'English only',
    ]])->assertOk();
    $this->withToken($token)->putJson("/api/v1/admin/pages/{$id}/translations/ar", [
        'title' => 'من نحن', 'slug' => 'من-نحن', 'summary' => 'فريقنا', 'body' => '<p>نحن.</p>',
    ])->assertOk();

    resetClient($this);

    // Robots is the site's policy, not English's noindex,nofollow; canonical is null only because
    // no public origin is configured, never English's address.
    expect($this->getJson('/api/v1/pages/من-نحن?locale=ar')->assertOk()->json('data.seo'))->toBe([
        'title' => 'من نحن',
        'description' => 'فريقنا',
        'robots' => 'index,follow',
        'canonical_url' => null,
        'og_title' => 'من نحن',
        'og_description' => 'فريقنا',
        'og_image_url' => null,
        'twitter_card' => 'summary',
    ]);
});

test('an SEO-only change purges the page and the list from the edge, and a save that changes nothing does not', function (): void {
    $token = seoPagesToken();
    $id = seoPublishedPage($this, $token);
    $this->edge->tags = [];

    $this->withToken($token)->putJson("/api/v1/admin/pages/{$id}/translations/en", ['seo' => ['title' => 'New title']])->assertOk();

    expect($this->edge->tags)->toContain(EdgeCacheTag::for('pages', $id), EdgeCacheTag::for('pages', 'list'));

    $this->edge->tags = [];
    $this->withToken($token)->putJson("/api/v1/admin/pages/{$id}/translations/en", ['seo' => ['title' => 'New title']])->assertOk();

    expect($this->edge->tags)->toBe([]);
});

test('a draft page\'s SEO is visible to its editors and to nobody else', function (): void {
    $token = seoPagesToken();
    $id = (string) $this->withToken($token)->postJson('/api/v1/admin/pages', ['sort_order' => 0])->json('data.id');

    $this->withToken($token)->putJson("/api/v1/admin/pages/{$id}/translations/en", [
        'title' => 'Unannounced', 'slug' => 'unannounced', 'body' => '<p>Soon.</p>', 'seo' => ['title' => 'Secret launch'],
    ])->assertOk()->assertJsonPath('data.seo.en.title', 'Secret launch');

    resetClient($this);
    $response = $this->getJson('/api/v1/pages/unannounced?locale=en')->assertNotFound();

    expect((string) $response->getContent())->not->toContain('Secret launch');
});

test('writing SEO needs the content\'s own update permission', function (): void {
    $id = seoPublishedPage($this, seoPagesToken());
    $reader = tokenWithPermissions([PagePermission::VIEW->value]);

    // The test client keeps the account it authenticated; a new token needs a fresh client.
    resetClient($this);
    $this->withToken($reader)->putJson("/api/v1/admin/pages/{$id}/translations/en", ['seo' => ['title' => 'Hijacked']])
        ->assertForbidden();

    expect(SeoMeta::query()->where('seoable_id', $id)->exists())->toBeFalse();
});

test('an unsafe canonical address or robots value is refused before anything is stored', function (): void {
    $token = seoPagesToken();
    $id = seoPublishedPage($this, $token);

    $this->withToken($token)->putJson("/api/v1/admin/pages/{$id}/translations/en", ['seo' => ['canonical_url' => 'javascript:alert(1)']])
        ->assertStatus(422);
    $this->withToken($token)->putJson("/api/v1/admin/pages/{$id}/translations/en", ['seo' => ['robots' => 'all']])
        ->assertStatus(422);

    expect(SeoMeta::query()->where('seoable_id', $id)->exists())->toBeFalse();
});

test('deleting a page or a member deletes its SEO in every language', function (): void {
    $pages = seoPagesToken();
    $page = seoPublishedPage($this, $pages);
    $this->withToken($pages)->putJson("/api/v1/admin/pages/{$page}/translations/en", ['seo' => ['title' => 'About']])->assertOk();

    $team = tokenWithPermissions([TeamPermission::VIEW->value, TeamPermission::CREATE->value, TeamPermission::UPDATE->value, TeamPermission::DELETE->value]);
    resetClient($this);
    $member = (string) $this->withToken($team)->postJson('/api/v1/admin/team', [])->assertCreated()->json('data.id');
    $this->withToken($team)->putJson("/api/v1/admin/team/{$member}/translations/en", ['name' => 'Nadia', 'position' => 'Editor', 'seo' => ['title' => 'Nadia']])->assertOk();
    $this->withToken($team)->putJson("/api/v1/admin/team/{$member}/translations/ar", ['name' => 'نادية', 'position' => 'محرّرة', 'seo' => ['title' => 'نادية']])->assertOk();

    expect(SeoMeta::query()->whereIn('seoable_id', [$page, $member])->count())->toBe(3);

    resetClient($this);
    $this->withToken($pages)->deleteJson("/api/v1/admin/pages/{$page}")->assertOk();
    resetClient($this);
    $this->withToken($team)->deleteJson("/api/v1/admin/team/{$member}")->assertOk();

    expect(SeoMeta::query()->whereIn('seoable_id', [$page, $member])->count())->toBe(0);
});

test('a member\'s sharing image falls back to their picture, and an SEO change purges the member', function (): void {
    $token = tokenWithPermissions([TeamPermission::VIEW->value, TeamPermission::CREATE->value, TeamPermission::UPDATE->value]);
    $avatar = seoContentImage();
    $member = (string) $this->withToken($token)->postJson('/api/v1/admin/team', ['avatar_media_id' => $avatar])->assertCreated()->json('data.id');

    $this->withToken($token)->putJson("/api/v1/admin/team/{$member}/translations/en", ['name' => 'Nadia Haddad', 'position' => 'Editor'])->assertOk();
    $this->withToken($token)->patchJson("/api/v1/admin/team/{$member}", ['is_active' => true])->assertOk();
    $this->edge->tags = [];

    $this->withToken($token)->putJson("/api/v1/admin/team/{$member}/translations/en", ['seo' => ['title' => 'Nadia | Team']])->assertOk();

    expect($this->edge->tags)->toContain(EdgeCacheTag::for('team', $member), EdgeCacheTag::for('team', 'list'));

    resetClient($this);
    $profile = $this->getJson('/api/v1/team/nadia-haddad?locale=en')->assertOk();

    expect($profile->json('data.seo.title'))->toBe('Nadia | Team')
        ->and($profile->json('data.seo.og_image_url'))->toBe($profile->json('data.avatar.url'))
        ->and($profile->json('data.seo.og_image_url'))->toBeString();
});
