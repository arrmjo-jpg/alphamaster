<?php

declare(strict_types=1);

use App\Modules\Core\Content\ContentRefusedException;
use App\Modules\Core\Models\BaseModel;
use App\Modules\Core\Seo\HasSeoMeta;
use App\Modules\Core\Seo\SeoFields;
use App\Modules\Core\Seo\SeoMeta;
use App\Modules\Core\Seo\SeoMetaStore;
use App\Modules\Media\Enums\MediaStatus;
use App\Modules\Media\Enums\MediaType;
use App\Modules\Media\Enums\MediaVisibility;
use App\Modules\Media\Enums\ScanStatus;
use App\Modules\Media\Models\MediaFile;
use App\Modules\Settings\Database\Seeders\SettingSeeder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;

/*
 * SEO is a Core capability (ADR 0032), not something Pages and Team have.
 *
 * A module that does not exist yet — an article, a competition, a fixture — is modelled here
 * by a model this file declares and a table it creates. It uses nothing but Core: the trait, the
 * store and the rules. No `seo_meta` of its own, no resolver, no validation, no endpoint.
 */

uses(RefreshDatabase::class);

/** A consumer that does not exist in the platform, standing in for any future module. */
final class SeoFutureArticle extends BaseModel
{
    use HasSeoMeta;

    protected $table = 'seo_future_articles';

    /** @var list<string> */
    protected $fillable = ['headline'];
}

beforeEach(function (): void {
    Cache::flush();
    $this->seed(SettingSeeder::class);

    Schema::create('seo_future_articles', function (Blueprint $table): void {
        $table->ulid('id')->primary();
        $table->string('headline');
        $table->timestampsTz();
    });
});

function seoImage(array $overrides = []): string
{
    $file = new MediaFile;
    $file->forceFill(array_merge([
        'collection' => 'content',
        'disk' => 'local',
        'path' => 'media/'.uniqid('seo_', true).'.png',
        'original_filename' => 'share.png',
        'mime_type' => 'image/png',
        'extension' => 'png',
        'type' => MediaType::IMAGE,
        'size_bytes' => 68,
        'checksum' => str_repeat('b', 64),
        'visibility' => MediaVisibility::PUBLIC,
        'status' => MediaStatus::READY,
        'scan_status' => ScanStatus::NOT_SCANNED,
        'width' => 1200,
        'height' => 630,
    ], $overrides))->save();

    return $file->id;
}

test('a future module stores per-language SEO through Core, one row per language', function (): void {
    $store = app(SeoMetaStore::class);
    $article = SeoFutureArticle::query()->create(['headline' => 'Final result']);

    expect($store->write($article, 'en', SeoFields::fromArray(['title' => 'Final result | Site'])))->toBeTrue()
        // The same fields again change nothing, and say so.
        ->and($store->write($article, 'en', SeoFields::fromArray(['title' => 'Final result | Site'])))->toBeFalse()
        ->and($store->write($article, 'ar', SeoFields::fromArray(['description' => 'النتيجة النهائية'])))->toBeTrue();

    expect($store->for($article, 'en')?->title)->toBe('Final result | Site')
        ->and($store->for($article, 'en')?->description)->toBeNull()
        ->and($store->for($article, 'fr'))->toBeNull()
        ->and(array_keys($store->all($article)))->toEqualCanonicalizing(['en', 'ar'])
        ->and(SeoMeta::query()->where('seoable_type', $article->getMorphClass())->count())->toBe(2);
});

test('clearing every field removes the language\'s row rather than keeping an empty one', function (): void {
    $store = app(SeoMetaStore::class);
    $article = SeoFutureArticle::query()->create(['headline' => 'Draw']);

    $store->write($article, 'en', SeoFields::fromArray(['title' => 'Draw']));

    expect($store->write($article, 'en', SeoFields::fromArray(['title' => '  '])))->toBeTrue()
        ->and(SeoMeta::query()->where('seoable_id', $article->id)->exists())->toBeFalse();
});

test('resolution never leaves the language, and falls back to the content in that language', function (): void {
    $store = app(SeoMetaStore::class);
    $article = SeoFutureArticle::query()->create(['headline' => 'Match report']);

    $store->write($article, 'en', SeoFields::fromArray(['og_title' => 'Shared headline', 'robots' => 'noindex,follow']));

    // Arabic has no row, so nothing of the English one appears in it.
    $arabic = $store->resolve($store->for($article, 'ar'), 'تقرير المباراة', 'ملخّص')->toArray();

    expect($arabic)->toBe([
        'title' => 'تقرير المباراة',
        'description' => 'ملخّص',
        'robots' => null,
        'canonical_url' => null,
        'og_title' => 'تقرير المباراة',
        'og_description' => 'ملخّص',
        'og_image_url' => null,
    ]);

    $english = $store->resolve($store->for($article, 'en'), 'Match report', null, 'https://cdn.example.test/avatar.png')->toArray();

    expect($english['title'])->toBe('Match report')
        ->and($english['og_title'])->toBe('Shared headline')
        ->and($english['robots'])->toBe('noindex,follow')
        ->and($english['og_image_url'])->toBe('https://cdn.example.test/avatar.png');
});

test('a sharing image is a media reference: a ready public image resolves, anything else is refused', function (): void {
    $store = app(SeoMetaStore::class);
    $article = SeoFutureArticle::query()->create(['headline' => 'Lineup']);
    $image = seoImage();

    $store->write($article, 'en', SeoFields::fromArray(['og_media_id' => $image]));

    expect($store->resolve($store->for($article, 'en'), 'Lineup', null)->ogImageUrl)->toBeString();

    foreach ([
        seoImage(['visibility' => MediaVisibility::PRIVATE]),
        seoImage(['status' => MediaStatus::PROCESSING]),
        seoImage(['scan_status' => ScanStatus::INFECTED]),
        '01J00000000000000000000000',
    ] as $unusable) {
        expect(fn () => $store->write($article, 'en', SeoFields::fromArray(['og_media_id' => $unusable])))
            ->toThrow(ContentRefusedException::class);
    }

    // A refused write left what was stored alone.
    expect($store->for($article, 'en')?->ogMediaId)->toBe($image);
});

test('deleting a model deletes its SEO, and only its SEO', function (): void {
    $store = app(SeoMetaStore::class);
    $gone = SeoFutureArticle::query()->create(['headline' => 'Gone']);
    $kept = SeoFutureArticle::query()->create(['headline' => 'Kept']);

    $store->write($gone, 'en', SeoFields::fromArray(['title' => 'Gone']));
    $store->write($gone, 'ar', SeoFields::fromArray(['title' => 'محذوف']));
    $store->write($kept, 'en', SeoFields::fromArray(['title' => 'Kept']));

    $gone->delete();

    expect(SeoMeta::query()->where('seoable_id', $gone->id)->count())->toBe(0)
        ->and($store->for($kept, 'en')?->title)->toBe('Kept');
});

test('Core\'s rules are the only SEO validation a module needs, and they refuse unsafe values', function (): void {
    $fails = static fn (array $seo): bool => Validator::make(['seo' => $seo], SeoFields::rules())->fails();

    expect($fails(['canonical_url' => 'javascript:alert(1)']))->toBeTrue()
        ->and($fails(['canonical_url' => 'data:text/html,<script>alert(1)</script>']))->toBeTrue()
        ->and($fails(['canonical_url' => '//evil.example.test/x']))->toBeTrue()
        ->and($fails(['robots' => 'all']))->toBeTrue()
        ->and($fails(['title' => str_repeat('x', SeoFields::TITLE_MAX + 1)]))->toBeTrue()
        ->and($fails(['description' => str_repeat('x', SeoFields::DESCRIPTION_MAX + 1)]))->toBeTrue()
        ->and($fails(['og_media_id' => 'not-a-ulid']))->toBeTrue()
        ->and($fails([
            'title' => 'Final | Site',
            'description' => 'A description',
            'robots' => 'index,follow',
            'canonical_url' => 'https://example.test/articles/final',
            'og_title' => 'Final',
            'og_description' => 'Shared',
        ]))->toBeFalse();

    // Under a module's own key, the same rules.
    expect(Validator::make(['meta' => ['robots' => 'all']], SeoFields::rules('meta'))->fails())->toBeTrue();
});

test('markup typed into a text field is stored and returned as text, never interpreted', function (): void {
    $store = app(SeoMetaStore::class);
    $article = SeoFutureArticle::query()->create(['headline' => 'Headline']);
    $markup = '<script>alert(1)</script> Title';

    $store->write($article, 'en', SeoFields::fromArray(['title' => $markup]));

    // The API returns JSON: the value is a string, and escaping it is the renderer's job, as
    // for every other text field. Nothing strips it into something else on the way.
    expect($store->resolve($store->for($article, 'en'), 'Headline', null)->title)->toBe($markup);
});
