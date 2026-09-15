<?php

declare(strict_types=1);

use App\Modules\Authorization\Database\Seeders\AdminPermissionSeeder;
use App\Modules\Core\Contracts\EdgeCacheContract;
use App\Modules\Core\Contracts\PublicUrlContract;
use App\Modules\Core\Delivery\EdgeCacheTag;
use App\Modules\Core\Delivery\EdgeInvalidation;
use App\Modules\Core\Delivery\EdgeInvalidationKind;
use App\Modules\Core\Delivery\EdgeInvalidationReceipt;
use App\Modules\Core\Models\BaseModel;
use App\Modules\Core\Seo\PublicRoute;
use App\Modules\Core\Seo\ResolvedSeo;
use App\Modules\Core\Seo\RobotsTxt;
use App\Modules\Core\Seo\SeoMetaStore;
use App\Modules\Core\Seo\Sitemap\SitemapEntry;
use App\Modules\Core\Seo\Sitemap\SitemapRegistry;
use App\Modules\Core\Seo\Sitemap\SitemapRenderer;
use App\Modules\Core\Seo\Sitemap\SitemapSource;
use App\Modules\Core\Seo\StructuredData\StructuredData;
use App\Modules\Core\Seo\StructuredData\StructuredDataGenerator;
use App\Modules\Localization\Database\Seeders\LanguageSeeder;
use App\Modules\Localization\Models\Language;
use App\Modules\Media\Contracts\MediaServiceContract;
use App\Modules\Media\Enums\MediaStatus;
use App\Modules\Media\Enums\MediaType;
use App\Modules\Media\Enums\MediaVisibility;
use App\Modules\Media\Enums\ScanStatus;
use App\Modules\Media\Models\MediaFile;
use App\Modules\Pages\Enums\PagePermission;
use App\Modules\Settings\Contracts\SettingServiceInterface;
use App\Modules\Settings\Database\Seeders\SettingSeeder;
use App\Modules\Settings\Models\Setting;
use App\Modules\Team\Enums\TeamPermission;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;

/*
 * The SEO platform (ADR 0058): public addresses, sitemap, robots, site defaults, structured data
 * and media invalidation, with Pages and Team as consumers and a module that does not exist —
 * articles — registered by this file alone, without any change to Core.
 */

uses(RefreshDatabase::class);

const SEO_ORIGIN = 'https://www.example.test';

beforeEach(function (): void {
    Cache::flush();

    $this->seed(SettingSeeder::class);
    $this->seed(LanguageSeeder::class);
    $this->seed(AdminPermissionSeeder::class);

    Language::query()->update(['is_default' => false]);
    Language::query()->where('code', 'en')->update(['is_default' => true, 'is_active' => true]);
    Language::query()->where('code', 'ar')->update(['is_active' => true]);

    app(SettingServiceInterface::class)->set('general', 'frontend_url', SEO_ORIGIN);
    Cache::flush();

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

function platformImage(array $overrides = []): string
{
    $file = new MediaFile;
    $file->forceFill(array_merge([
        'collection' => 'content',
        'disk' => 'local',
        'path' => 'media/'.uniqid('platform_', true).'.png',
        'original_filename' => 'share.png',
        'mime_type' => 'image/png',
        'extension' => 'png',
        'type' => MediaType::IMAGE,
        'size_bytes' => 68,
        'checksum' => str_repeat('d', 64),
        'visibility' => MediaVisibility::PUBLIC,
        'status' => MediaStatus::READY,
        'scan_status' => ScanStatus::NOT_SCANNED,
        'width' => 1200,
        'height' => 630,
    ], $overrides))->save();

    return $file->id;
}

function platformPagesToken(): string
{
    return tokenWithPermissions([
        PagePermission::VIEW->value, PagePermission::CREATE->value, PagePermission::UPDATE->value,
        PagePermission::PUBLISH->value, PagePermission::DELETE->value,
    ]);
}

/**
 * @param  array<string, array<string, mixed>>  $translations  locale => fields
 */
function platformPage(mixed $test, string $token, array $translations, bool $publish = true): string
{
    $id = (string) $test->withToken($token)->postJson('/api/v1/admin/pages', ['sort_order' => 0])->assertCreated()->json('data.id');

    foreach ($translations as $locale => $fields) {
        $test->withToken($token)->putJson("/api/v1/admin/pages/{$id}/translations/{$locale}", $fields)->assertOk();
    }

    if ($publish) {
        $test->withToken($token)->postJson("/api/v1/admin/pages/{$id}/publish")->assertOk();
    }

    return $id;
}

function localizedSetting(string $group, string $key, string $locale, string $value): void
{
    Setting::query()->where('group', $group)->where('key', $key)->firstOrFail()->setLocalizedValue($locale, $value);
    Cache::flush();
}

// ── Public addresses ─────────────────────────────────────────────────────────

test('pages and team members have an absolute address in each language, with alternates', function (): void {
    $token = platformPagesToken();
    platformPage($this, $token, [
        'en' => ['title' => 'About', 'slug' => 'about', 'body' => '<p>About.</p>'],
        'ar' => ['title' => 'من نحن', 'slug' => 'من-نحن', 'body' => '<p>نحن.</p>'],
    ]);

    resetClient($this);
    $page = $this->getJson('/api/v1/pages/about?locale=en')->assertOk();
    $arabic = SEO_ORIGIN.'/ar/pages/'.rawurlencode('من-نحن');

    expect($page->json('data.url'))->toBe(SEO_ORIGIN.'/en/pages/about')
        ->and($page->json('data.seo.canonical_url'))->toBe(SEO_ORIGIN.'/en/pages/about')
        ->and($page->json('data.alternates'))->toBe([['locale' => 'ar', 'slug' => 'من-نحن', 'url' => $arabic]]);

    // The Arabic address's canonical is its own, never the English one.
    expect($this->getJson('/api/v1/pages/من-نحن?locale=ar')->json('data.seo.canonical_url'))->toBe($arabic);

    $team = tokenWithPermissions([TeamPermission::VIEW->value, TeamPermission::CREATE->value, TeamPermission::UPDATE->value]);
    resetClient($this);
    $member = (string) $this->withToken($team)->postJson('/api/v1/admin/team', [])->assertCreated()->json('data.id');
    $this->withToken($team)->putJson("/api/v1/admin/team/{$member}/translations/en", ['name' => 'Nadia Haddad', 'position' => 'Editor'])->assertOk();
    $this->withToken($team)->patchJson("/api/v1/admin/team/{$member}", ['is_active' => true])->assertOk();

    resetClient($this);
    expect($this->getJson('/api/v1/team/nadia-haddad?locale=en')->json('data.url'))->toBe(SEO_ORIGIN.'/en/team/nadia-haddad');
});

test('an operator\'s canonical override replaces the default in that language only', function (): void {
    $token = platformPagesToken();
    $id = platformPage($this, $token, ['en' => ['title' => 'Terms', 'slug' => 'terms', 'body' => '<p>Terms.</p>']]);

    $this->withToken($token)->putJson("/api/v1/admin/pages/{$id}/translations/en", ['seo' => ['canonical_url' => 'https://legal.example.test/terms']])->assertOk();

    resetClient($this);
    expect($this->getJson('/api/v1/pages/terms?locale=en')->json('data.seo.canonical_url'))->toBe('https://legal.example.test/terms');
});

test('a future module declares its address pattern and gets addresses from Core, and a conflicting declaration is refused', function (): void {
    $urls = app(PublicUrlContract::class);
    $urls->register(new PublicRoute('articles', '/{locale}/articles/{slug}'));

    expect($urls->url('articles', 'ar', ['slug' => 'النهائي']))->toBe(SEO_ORIGIN.'/ar/articles/'.rawurlencode('النهائي'))
        ->and($urls->url('articles', 'en', []))->toBeNull()
        ->and($urls->url('unknown', 'en', ['slug' => 'x']))->toBeNull()
        ->and(fn () => $urls->register(new PublicRoute('articles', '/{locale}/news/{slug}')))->toThrow(LogicException::class)
        ->and(fn () => new PublicRoute('articles', 'articles/{slug}'))->toThrow(InvalidArgumentException::class);

    app(SettingServiceInterface::class)->set('general', 'frontend_url', null);
    Cache::flush();

    // No origin, no invented address.
    expect($urls->url('articles', 'en', ['slug' => 'final']))->toBeNull();
});

// ── Sitemap ──────────────────────────────────────────────────────────────────

test('the sitemap lists live pages in served languages with alternates, and nothing else', function (): void {
    $token = platformPagesToken();

    platformPage($this, $token, [
        'en' => ['title' => 'About', 'slug' => 'about', 'body' => '<p>About.</p>'],
        'ar' => ['title' => 'من نحن', 'slug' => 'من-نحن', 'body' => '<p>نحن.</p>'],
    ]);
    platformPage($this, $token, ['en' => ['title' => 'Draft', 'slug' => 'draft-page', 'body' => '<p>Soon.</p>']], publish: false);
    $archived = platformPage($this, $token, ['en' => ['title' => 'Old', 'slug' => 'old-page', 'body' => '<p>Old.</p>']]);
    $this->withToken($token)->postJson("/api/v1/admin/pages/{$archived}/archive")->assertOk();
    $hidden = platformPage($this, $token, ['en' => ['title' => 'Hidden', 'slug' => 'hidden-page', 'body' => '<p>Hidden.</p>']]);
    $this->withToken($token)->putJson("/api/v1/admin/pages/{$hidden}/translations/en", ['seo' => ['robots' => 'noindex,follow']])->assertOk();
    // A language that is not served is never listed, however complete.
    Language::query()->create(['code' => 'fr', 'name' => 'French', 'native_name' => 'Français', 'direction' => 'ltr', 'is_active' => false, 'is_default' => false, 'sort_order' => 9]);
    Cache::flush();

    resetClient($this);
    $index = $this->get('/api/v1/sitemap.xml')->assertOk();

    expect($index->headers->get('Content-Type'))->toContain('application/xml')
        ->and((string) $index->getContent())->toContain('<loc>'.SEO_ORIGIN.'/sitemaps/pages-1.xml</loc>');

    $xml = (string) $this->get('/api/v1/sitemaps/pages-1.xml')->assertOk()->getContent();
    $arabic = SEO_ORIGIN.'/ar/pages/'.rawurlencode('من-نحن');

    expect($xml)->toContain('<loc>'.SEO_ORIGIN.'/en/pages/about</loc>')
        ->and($xml)->toContain('<loc>'.$arabic.'</loc>')
        ->and($xml)->toContain('hreflang="ar" href="'.$arabic.'"')
        ->and($xml)->toContain('hreflang="x-default" href="'.SEO_ORIGIN.'/en/pages/about"')
        ->and($xml)->toContain('<lastmod>')
        ->and($xml)->not->toContain('draft-page')
        ->and($xml)->not->toContain('old-page')
        ->and($xml)->not->toContain('hidden-page')
        ->and($xml)->not->toContain('/fr/');

    $this->get('/api/v1/sitemaps/unknown-1.xml')->assertNotFound();
});

test('the sitemap splits a source into files and a future module registers its own source', function (): void {
    $token = platformPagesToken();
    platformPage($this, $token, ['en' => ['title' => 'One', 'slug' => 'one', 'body' => '<p>1</p>']]);
    platformPage($this, $token, ['en' => ['title' => 'Two', 'slug' => 'two', 'body' => '<p>2</p>']]);

    app(SitemapRenderer::class)->chunkSize(1);
    app(SitemapRegistry::class)->register(new class implements SitemapSource
    {
        public function key(): string
        {
            return 'articles';
        }

        public function count(): int
        {
            return 1;
        }

        public function entries(): iterable
        {
            yield new SitemapEntry(SEO_ORIGIN.'/en/articles/final', null, ['en' => SEO_ORIGIN.'/en/articles/final']);
        }
    });

    resetClient($this);
    $index = (string) $this->get('/api/v1/sitemap.xml')->assertOk()->getContent();

    expect($index)->toContain('/sitemaps/pages-1.xml')
        ->and($index)->toContain('/sitemaps/pages-2.xml')
        ->and($index)->toContain('/sitemaps/articles-1.xml');

    expect(substr_count((string) $this->get('/api/v1/sitemaps/pages-1.xml')->getContent(), '<url>'))->toBe(1)
        ->and(substr_count((string) $this->get('/api/v1/sitemaps/pages-2.xml')->getContent(), '<url>'))->toBe(1)
        ->and((string) $this->get('/api/v1/sitemaps/articles-1.xml')->getContent())->toContain('/en/articles/final');

    $this->get('/api/v1/sitemaps/pages-3.xml')->assertNotFound();
});

test('without a public origin there is no sitemap, rather than one of relative addresses', function (): void {
    app(SettingServiceInterface::class)->set('general', 'frontend_url', null);
    Cache::flush();

    $this->get('/api/v1/sitemap.xml')->assertNotFound();
});

test('a site robots policy of noindex removes content that sets none from the sitemap', function (): void {
    $token = platformPagesToken();
    platformPage($this, $token, ['en' => ['title' => 'About', 'slug' => 'about', 'body' => '<p>About.</p>']]);

    app(SettingServiceInterface::class)->set('seo', 'robots_policy', 'noindex,follow');
    Cache::flush();

    resetClient($this);
    expect($this->getJson('/api/v1/pages/about?locale=en')->json('data.seo.robots'))->toBe('noindex,follow')
        ->and((string) $this->get('/api/v1/sitemaps/pages-1.xml')->getContent())->not->toContain('/en/pages/about');
});

test('publishing and editing purge the sitemap along with the page', function (): void {
    $token = platformPagesToken();
    $id = platformPage($this, $token, ['en' => ['title' => 'About', 'slug' => 'about', 'body' => '<p>About.</p>']]);
    $this->edge->tags = [];

    $this->withToken($token)->putJson("/api/v1/admin/pages/{$id}/translations/en", ['seo' => ['title' => 'About us']])->assertOk();

    expect($this->edge->tags)->toContain(SitemapRenderer::sourceTag('pages'), SitemapRenderer::indexTag(), EdgeCacheTag::for('pages', $id));
});

// ── Robots ───────────────────────────────────────────────────────────────────

test('outside production robots.txt disallows everything, whatever is configured', function (): void {
    app(SettingServiceInterface::class)->set('seo', 'robots_extra', "Allow: /\nDisallow: /secret");
    Cache::flush();

    $response = $this->get('/api/v1/robots.txt')->assertOk();

    expect($response->headers->get('Content-Type'))->toContain('text/plain')
        ->and((string) $response->getContent())->toBe("User-agent: *\nDisallow: /\n");
});

test('in production robots.txt allows crawling, excludes the admin API, adds only valid extra directives and names the sitemap', function (): void {
    app(SettingServiceInterface::class)->set('seo', 'robots_extra', "Disallow: /search\n<script>alert(1)</script>\nX-Evil: yes\ncrawl-delay: 5\nNot a directive");
    Cache::flush();

    expect(app(RobotsTxt::class)->render(production: true))->toBe(implode("\n", [
        'User-agent: *',
        'Disallow: /api/v1/admin',
        'Disallow: /search',
        'Crawl-delay: 5',
        'Sitemap: '.SEO_ORIGIN.'/sitemap.xml',
    ])."\n");

    app()['env'] = 'production';

    expect((string) $this->get('/api/v1/robots.txt')->assertOk()->getContent())->toContain('Disallow: /api/v1/admin');
});

// ── Site defaults ────────────────────────────────────────────────────────────

test('site defaults resolve in the language asked for and never borrow another language', function (): void {
    localizedSetting('general', 'site_name', 'ar', 'منصة ألفا');
    localizedSetting('general', 'site_description', 'en', 'The platform');
    $store = app(SeoMetaStore::class);

    // No content title: the site's name in the same language, and in a language without one,
    // nothing.
    expect($store->resolve(null, 'ar', '', null)->title)->toBe('منصة ألفا')
        ->and($store->resolve(null, 'fr', '', null)->title)->toBe('')
        ->and($store->resolve(null, 'en', 'Content', null)->description)->toBe('The platform')
        // English has a description; Arabic does not, and gets none.
        ->and($store->resolve(null, 'ar', 'محتوى', null)->description)->toBeNull()
        ->and($store->resolve(null, 'ar', 'محتوى', null)->robots)->toBe('index,follow');
});

test('the default sharing image is used when content has none, as a large image card', function (): void {
    $image = platformImage();
    app(SettingServiceInterface::class)->set('branding', 'og_image', $image);
    Cache::flush();

    $token = platformPagesToken();
    platformPage($this, $token, ['en' => ['title' => 'About', 'slug' => 'about', 'body' => '<p>About.</p>']]);

    resetClient($this);
    $seo = $this->getJson('/api/v1/pages/about?locale=en')->json('data.seo');

    expect($seo['og_image_url'])->toBeString()
        ->and($seo['twitter_card'])->toBe('summary_large_image');
});

// ── Structured data ──────────────────────────────────────────────────────────

test('a page carries WebSite, Organization and WebPage JSON-LD with absolute addresses in its language', function (): void {
    localizedSetting('general', 'site_name', 'ar', 'منصة ألفا');
    $token = platformPagesToken();
    platformPage($this, $token, [
        'en' => ['title' => 'About', 'slug' => 'about', 'body' => '<p>About.</p>'],
        'ar' => ['title' => 'من نحن', 'slug' => 'من-نحن', 'summary' => 'فريقنا', 'body' => '<p>نحن.</p>'],
    ]);

    resetClient($this);
    $data = $this->getJson('/api/v1/pages/من-نحن?locale=ar')->json('data.structured_data');
    [$website, $organization, $webpage] = $data['@graph'];

    expect($data['@context'])->toBe('https://schema.org')
        ->and($website)->toMatchArray(['@type' => 'WebSite', 'url' => SEO_ORIGIN.'/', 'name' => 'منصة ألفا', 'inLanguage' => 'ar'])
        ->and($organization)->toMatchArray(['@type' => 'Organization', '@id' => SEO_ORIGIN.'/#organization'])
        ->and($webpage)->toMatchArray([
            '@type' => 'WebPage',
            'url' => SEO_ORIGIN.'/ar/pages/'.rawurlencode('من-نحن'),
            'name' => 'من نحن',
            'description' => 'فريقنا',
            'inLanguage' => 'ar',
            'isPartOf' => ['@id' => SEO_ORIGIN.'/#website'],
        ]);
});

test('a team member carries Person JSON-LD with their picture and links', function (): void {
    $token = tokenWithPermissions([TeamPermission::VIEW->value, TeamPermission::CREATE->value, TeamPermission::UPDATE->value]);
    $avatar = platformImage();
    $member = (string) $this->withToken($token)->postJson('/api/v1/admin/team', [
        'avatar_media_id' => $avatar, 'social_links' => ['website' => 'https://nadia.example.test'],
    ])->assertCreated()->json('data.id');
    $this->withToken($token)->putJson("/api/v1/admin/team/{$member}/translations/en", ['name' => 'Nadia Haddad', 'position' => 'Editor'])->assertOk();
    $this->withToken($token)->patchJson("/api/v1/admin/team/{$member}", ['is_active' => true])->assertOk();

    resetClient($this);
    $profile = $this->getJson('/api/v1/team/nadia-haddad?locale=en');
    $person = $profile->json('data.structured_data.@graph.2');

    expect($person)->toMatchArray([
        '@type' => 'Person',
        'url' => SEO_ORIGIN.'/en/team/nadia-haddad',
        'name' => 'Nadia Haddad',
        'jobTitle' => 'Editor',
        'image' => $profile->json('data.avatar.url'),
        'sameAs' => ['https://nadia.example.test'],
    ]);
});

test('a future module registers a schema generator and Core wraps it in the site graph, as plain text', function (): void {
    $schema = app(StructuredData::class);
    $schema->register(new class implements StructuredDataGenerator
    {
        public function key(): string
        {
            return 'articles';
        }

        public function node(Model $owner, string $locale, ResolvedSeo $seo, ?string $url): array
        {
            return ['@type' => 'Article', 'headline' => $seo->title, 'url' => $url, 'author' => null];
        }
    });

    $seo = new ResolvedSeo('<script>alert(1)</script> Final', null, 'index,follow', null, 'Final', null, null);
    $graph = $schema->for('articles', new class extends BaseModel {}, 'en', $seo, SEO_ORIGIN.'/en/articles/final');

    expect($graph['@graph'][2])->toBe([
        '@type' => 'Article',
        'headline' => 'alert(1) Final',
        'url' => SEO_ORIGIN.'/en/articles/final',
        'isPartOf' => ['@id' => SEO_ORIGIN.'/#website'],
    ])->and(json_encode($graph))->not->toContain('<script')
        ->and($schema->for('unregistered', new class extends BaseModel {}, 'en', $seo, null))->toBeNull();
});

// ── Media ────────────────────────────────────────────────────────────────────

test('deleting or failing a sharing image purges the pages that show it, and only those', function (): void {
    $token = platformPagesToken();
    $image = platformImage();
    $other = platformImage();
    $shows = platformPage($this, $token, ['en' => ['title' => 'About', 'slug' => 'about', 'body' => '<p>About.</p>', 'seo' => ['og_media_id' => $image]]]);
    $unrelated = platformPage($this, $token, ['en' => ['title' => 'Terms', 'slug' => 'terms', 'body' => '<p>Terms.</p>']]);

    $this->edge->tags = [];
    app(MediaServiceContract::class)->delete(MediaFile::query()->findOrFail($image));

    expect($this->edge->tags)->toContain(EdgeCacheTag::for('pages', $shows))
        ->and($this->edge->tags)->not->toContain(EdgeCacheTag::for('pages', $unrelated));

    // The purged page no longer names the deleted image.
    resetClient($this);
    expect($this->getJson('/api/v1/pages/about?locale=en')->json('data.seo.og_image_url'))->toBeNull();

    $this->withToken($token)->putJson("/api/v1/admin/pages/{$shows}/translations/en", ['seo' => ['og_media_id' => $other]])->assertOk();
    $this->edge->tags = [];

    MediaFile::query()->findOrFail($other)->markFailed(MediaStatus::SCAN_FAILED, 'Rejected.');

    expect($this->edge->tags)->toContain(EdgeCacheTag::for('pages', $shows));

    // A file nothing refers to purges nothing.
    $this->edge->tags = [];
    app(MediaServiceContract::class)->delete(MediaFile::query()->findOrFail(platformImage()));

    expect($this->edge->tags)->toBe([]);
});

test('deleting a member\'s picture purges their profile and the directory', function (): void {
    $token = tokenWithPermissions([TeamPermission::VIEW->value, TeamPermission::CREATE->value, TeamPermission::UPDATE->value]);
    $avatar = platformImage();
    $member = (string) $this->withToken($token)->postJson('/api/v1/admin/team', ['avatar_media_id' => $avatar])->assertCreated()->json('data.id');
    $this->edge->tags = [];

    app(MediaServiceContract::class)->delete(MediaFile::query()->findOrFail($avatar));

    expect($this->edge->tags)->toContain(EdgeCacheTag::for('team', $member), EdgeCacheTag::for('team', 'list'));
});

// ── Security ─────────────────────────────────────────────────────────────────

test('a draft appears in no sitemap, no structured data and no public response', function (): void {
    $token = platformPagesToken();
    platformPage($this, $token, ['en' => ['title' => 'Secret launch', 'slug' => 'secret-launch', 'body' => '<p>Soon.</p>']], publish: false);
    platformPage($this, $token, ['en' => ['title' => 'About', 'slug' => 'about', 'body' => '<p>About.</p>']]);

    resetClient($this);

    expect((string) $this->get('/api/v1/sitemaps/pages-1.xml')->getContent())->not->toContain('secret-launch')
        ->and((string) $this->getJson('/api/v1/pages/about?locale=en')->getContent())->not->toContain('Secret launch');

    $this->getJson('/api/v1/pages/secret-launch?locale=en')->assertNotFound();
});
