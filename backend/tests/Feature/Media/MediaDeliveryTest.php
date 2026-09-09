<?php

declare(strict_types=1);

use App\Modules\Authorization\Database\Seeders\AdminPermissionSeeder;
use App\Modules\Media\Contracts\MediaServiceContract;
use App\Modules\Media\Data\MediaUpload;
use App\Modules\Media\Enums\MediaVisibility;
use App\Modules\Media\Models\MediaFile;
use App\Modules\Settings\Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

/**
 * Serving the bytes.
 *
 * The platform is API-only and the proxy in front of it reads no files, so a stored
 * object has no other route to a browser: `Storage::url()` composes a path against a
 * public disk and a symlink that this deployment does not have, and what answers that
 * path is whatever else is listening. These tests are as much about that — the URL a
 * caller is handed has to be one that serves the file — as about the endpoint.
 */
beforeEach(function (): void {
    Cache::flush();
    config(['queue.default' => 'sync']);
    Storage::fake('local');

    $this->seed(SettingSeeder::class);
    $this->seed(AdminPermissionSeeder::class);

    $this->media = app(MediaServiceContract::class);
});

function deliveryPng(string $name = 'logo.png'): UploadedFile
{
    $path = tempnam(sys_get_temp_dir(), 'alpha_delivery_');
    file_put_contents($path, base64_decode(
        'iVBORw0KGgoAAAANSUhEUgAAAAQAAAAECAYAAACp8Z5+AAAAFUlEQVR42mNk'
        .'+M+ACzDhVQGVUEsBAP7bA/1B9AAAAABJRU5ErkJggg=='
    ));

    return new UploadedFile($path, $name, 'image/png', null, true);
}

function storeMedia(object $test, MediaVisibility $visibility, ?string $uploadedBy = null): MediaFile
{
    return $test->media->store(new MediaUpload(
        file: deliveryPng(),
        visibility: $visibility,
        collection: 'branding',
        uploadedBy: $uploadedBy,
    ));
}

test('the URL a caller is handed is one that serves the file', function (): void {
    $user = regularWithToken($this, 'delivery-url@example.com');
    $media = storeMedia($this, MediaVisibility::PUBLIC, $user['user']->id)->refresh();

    $url = $this->media->urlFor($media, $user['user']);

    // Relative, because the Admin and the API share one origin (ADR 0042) and an
    // absolute URL would have to guess at a host.
    expect($url)->toBe('/api/v1/media/'.$media->id.'/file');

    $this->withToken($user['token'])->get($url)->assertOk();
});

test('it serves the stored bytes with the type and length recorded for them', function (): void {
    $user = regularWithToken($this, 'delivery-bytes@example.com');
    $media = storeMedia($this, MediaVisibility::PUBLIC, $user['user']->id);

    $response = $this->withToken($user['token'])
        ->get('/api/v1/media/'.$media->id.'/file');

    $response->assertOk()
        ->assertHeader('Content-Type', 'image/png')
        ->assertHeader('Content-Length', (string) $media->size_bytes)
        ->assertHeader('X-Content-Type-Options', 'nosniff');

    expect(strlen($response->streamedContent()))->toBe($media->size_bytes);
});

test('a shared cache is never allowed to keep a copy', function (): void {
    // The route is behind authentication whatever the media's visibility, so a
    // response held by a shared cache would be handed to a caller who was never
    // authorized for it.
    $user = regularWithToken($this, 'delivery-cache@example.com');
    $media = storeMedia($this, MediaVisibility::PUBLIC, $user['user']->id);

    $response = $this->withToken($user['token'])->get('/api/v1/media/'.$media->id.'/file');

    expect($response->headers->get('Cache-Control'))->toContain('private');
});

test('it answers a conditional request without reading the file', function (): void {
    $user = regularWithToken($this, 'delivery-conditional@example.com');
    $media = storeMedia($this, MediaVisibility::PUBLIC, $user['user']->id);

    $first = $this->withToken($user['token'])->get('/api/v1/media/'.$media->id.'/file');
    $etag = (string) $first->headers->get('ETag');

    expect($etag)->toBe('"'.$media->checksum.'"');

    // The stored object is immutable — a new upload never overwrites an old one — so
    // the checksum is a strong validator rather than a weak one.
    $this->withToken($user['token'])
        ->get('/api/v1/media/'.$media->id.'/file', ['If-None-Match' => $etag])
        ->assertStatus(304);
});

test('it reports a file somebody else may not see as one that does not exist', function (): void {
    $owner = regularWithToken($this, 'delivery-owner@example.com');
    $other = regularWithToken($this, 'delivery-other@example.com');

    $media = storeMedia($this, MediaVisibility::PRIVATE, $owner['user']->id);

    // 404 rather than 403, so this endpoint cannot be used to discover which media
    // ids are real.
    $this->withToken($other['token'])
        ->get('/api/v1/media/'.$media->id.'/file', ['Accept' => 'application/json'])
        ->assertStatus(404);

    // The guard resolves a user once per test process, so the second caller has to
    // be a genuinely fresh client or it inherits the first one's answer.
    resetClient($this);

    $this->withToken($owner['token'])
        ->get('/api/v1/media/'.$media->id.'/file')
        ->assertOk();
});

test('it requires a session at all', function (): void {
    $user = regularWithToken($this, 'delivery-anon@example.com');
    $media = storeMedia($this, MediaVisibility::PUBLIC, $user['user']->id);

    $this->get('/api/v1/media/'.$media->id.'/file', ['Accept' => 'application/json'])
        ->assertStatus(401);
});

test('a record whose bytes are gone is a not-found, not a failure', function (): void {
    $user = regularWithToken($this, 'delivery-missing@example.com');
    $media = storeMedia($this, MediaVisibility::PUBLIC, $user['user']->id);

    Storage::disk($media->disk)->delete($media->path);

    // Nothing is broken here. The object is gone, and that is what a caller needs to
    // be told — a 500 would send an operator looking for a fault that does not exist.
    $this->withToken($user['token'])
        ->get('/api/v1/media/'.$media->id.'/file', ['Accept' => 'application/json'])
        ->assertStatus(404);
});
