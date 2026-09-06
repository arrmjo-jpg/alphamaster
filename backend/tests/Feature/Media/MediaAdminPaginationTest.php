<?php

declare(strict_types=1);

use App\Modules\Authorization\Database\Seeders\AdminPermissionSeeder;
use App\Modules\Localization\Database\Seeders\LanguageSeeder;
use App\Modules\Media\Enums\MediaStatus;
use App\Modules\Media\Enums\MediaType;
use App\Modules\Media\Enums\MediaVisibility;
use App\Modules\Media\Enums\ScanStatus;
use App\Modules\Media\Models\MediaFile;
use App\Modules\Media\Resources\MediaAdminResource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;

uses(RefreshDatabase::class);

/** The page size the admin listing paginates at. */
const ADMIN_MEDIA_PER_PAGE = 25;

beforeEach(function (): void {
    Cache::flush();

    $this->seed(LanguageSeeder::class);
    $this->seed(AdminPermissionSeeder::class);
});

/**
 * Stored records, one second apart, newest last in the returned array.
 *
 * Uploads arrive at distinct moments in practice, and the listing orders by
 * `created_at`, so distinct moments are what makes the order well defined.
 *
 * @return list<MediaFile>
 */
function storedMedia(int $count, string $status = 'ready'): array
{
    $created = [];

    for ($i = 0; $i < $count; $i++) {
        $media = new MediaFile([
            'collection' => 'default',
            'original_filename' => sprintf('file-%02d.png', $i),
        ]);

        $media->forceFill([
            'disk' => 'public',
            'path' => sprintf('media/page/file-%02d.png', $i),
            'type' => MediaType::IMAGE,
            'mime_type' => 'image/png',
            'extension' => 'png',
            'size_bytes' => 128 + $i,
            'checksum' => str_pad((string) $i, 64, 'a', STR_PAD_LEFT),
            'visibility' => MediaVisibility::PRIVATE,
            'status' => $status,
            'scan_status' => ScanStatus::NOT_SCANNED,
            'width' => 10,
            'height' => 10,
            'created_at' => now()->subMinutes($count - $i),
            'updated_at' => now()->subMinutes($count - $i),
        ]);

        $media->save();

        $created[] = $media;
    }

    return $created;
}

/** An administrator who may read the media listing. */
function mediaListAdmin(mixed $test, string $email): string
{
    return adminWithRoles($test, ['super_admin'], $email)['token'];
}

test('the listing stops at one page and says how much is left', function (): void {
    // 26 records over a page size of 25: the first request must not quietly
    // return everything, and must say that a second page exists.
    storedMedia(ADMIN_MEDIA_PER_PAGE + 1);

    $token = mediaListAdmin($this, 'media-page-one@example.test');

    resetClient($this);
    $response = $this->withToken($token)->getJson('/api/v1/admin/media')->assertOk();

    expect($response->json('data'))->toHaveCount(ADMIN_MEDIA_PER_PAGE)
        ->and($response->json('meta.pagination'))->toBe([
            'current_page' => 1,
            'per_page' => ADMIN_MEDIA_PER_PAGE,
            'total' => ADMIN_MEDIA_PER_PAGE + 1,
            'last_page' => 2,
            'has_more_pages' => true,
        ]);
});

test('the second page carries the remainder and nothing from the first', function (): void {
    $all = storedMedia(ADMIN_MEDIA_PER_PAGE + 3);

    $token = mediaListAdmin($this, 'media-page-two@example.test');

    resetClient($this);
    $first = $this->withToken($token)->getJson('/api/v1/admin/media')->assertOk();

    resetClient($this);
    $second = $this->withToken($token)->getJson('/api/v1/admin/media?page=2')->assertOk();

    $firstIds = array_column($first->json('data'), 'id');
    $secondIds = array_column($second->json('data'), 'id');

    expect($secondIds)->toHaveCount(3)
        ->and($second->json('meta.pagination.current_page'))->toBe(2)
        ->and($second->json('meta.pagination.has_more_pages'))->toBeFalse()
        // No record is served twice, and none is skipped between the pages.
        ->and(array_intersect($firstIds, $secondIds))->toBe([])
        ->and(count(array_unique(array_merge($firstIds, $secondIds))))->toBe(count($all));

    sort($secondIds);
    $expected = array_map(fn (MediaFile $m): string => $m->id, array_slice($all, 0, 3));
    sort($expected);

    // The listing is newest first, so the oldest three fall to the last page.
    expect($secondIds)->toBe($expected);
});

test('paging keeps the newest-first order across the boundary', function (): void {
    $all = storedMedia(ADMIN_MEDIA_PER_PAGE + 2);

    $token = mediaListAdmin($this, 'media-page-order@example.test');

    resetClient($this);
    $first = array_column($this->withToken($token)->getJson('/api/v1/admin/media')->assertOk()->json('data'), 'id');

    resetClient($this);
    $second = array_column($this->withToken($token)->getJson('/api/v1/admin/media?page=2')->assertOk()->json('data'), 'id');

    // storedMedia returns oldest first, so the whole listing is its reverse.
    $expected = array_reverse(array_map(fn (MediaFile $m): string => $m->id, $all));

    // Asserted before the order, so this cannot pass by fitting on one page.
    expect($first)->toHaveCount(ADMIN_MEDIA_PER_PAGE)
        ->and($second)->toHaveCount(2)
        ->and(array_merge($first, $second))->toBe($expected);
});

test('a page beyond the last is empty rather than an error', function (): void {
    storedMedia(3);

    $token = mediaListAdmin($this, 'media-page-past@example.test');

    resetClient($this);
    $response = $this->withToken($token)->getJson('/api/v1/admin/media?page=9')->assertOk();

    expect($response->json('data'))->toBe([])
        ->and($response->json('meta.pagination.total'))->toBe(3)
        ->and($response->json('meta.pagination.has_more_pages'))->toBeFalse();
});

test('a filter narrows the pagination rather than only the page', function (): void {
    // The filter is applied before paginate(), so `total` must describe the
    // filtered set. A filter applied to the page alone would report 30 here.
    storedMedia(ADMIN_MEDIA_PER_PAGE + 1, MediaStatus::READY->value);
    storedMedia(4, MediaStatus::SCAN_FAILED->value);

    $token = mediaListAdmin($this, 'media-page-filter@example.test');

    resetClient($this);
    $response = $this->withToken($token)
        ->getJson('/api/v1/admin/media?status='.MediaStatus::SCAN_FAILED->value)
        ->assertOk();

    expect($response->json('data'))->toHaveCount(4)
        ->and($response->json('meta.pagination.total'))->toBe(4)
        ->and($response->json('meta.pagination.last_page'))->toBe(1)
        ->and($response->json('meta.pagination.has_more_pages'))->toBeFalse();
});

test('the envelope and the row shape are what the endpoint promises', function (): void {
    // Asserted against the endpoint rather than against the Resource in isolation:
    // a Resource can be correct while the controller wraps it differently.
    $media = storedMedia(1)[0];

    $token = mediaListAdmin($this, 'media-page-shape@example.test');

    resetClient($this);
    $body = $this->withToken($token)->getJson('/api/v1/admin/media')->assertOk()->json();

    // The listing passes no message, and the envelope omits the key rather than
    // sending a null one.
    expect(array_keys($body))->toBe(['success', 'data', 'meta'])
        ->and(array_keys($body['meta']))->toBe(['pagination'])
        ->and(array_keys($body['meta']['pagination']))->toBe([
            'current_page', 'per_page', 'total', 'last_page', 'has_more_pages',
        ])
        // The row the endpoint serves carries exactly the fields the admin
        // Resource declares — no more, so pagination cannot leak a raw model.
        ->and(array_keys($body['data'][0]))
        ->toBe(array_keys((new MediaAdminResource($media))->toArray(request())));
});
