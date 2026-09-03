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

beforeEach(function (): void {
    Cache::flush();
    config(['queue.default' => 'sync']);
    Storage::fake('local');

    $this->seed(SettingSeeder::class);
    $this->seed(AdminPermissionSeeder::class);

    $this->media = app(MediaServiceContract::class);
});

/**
 * A real PNG as a request would deliver it.
 */
function apiPng(string $name = 'photo.png'): UploadedFile
{
    $path = tempnam(sys_get_temp_dir(), 'alpha_api_');
    file_put_contents($path, base64_decode(
        'iVBORw0KGgoAAAANSUhEUgAAAAQAAAAECAYAAACp8Z5+AAAAFUlEQVR42mNk'
        .'+M+ACzDhVQGVUEsBAP7bA/1B9AAAAABJRU5ErkJggg=='
    ));

    return new UploadedFile($path, $name, 'image/png', null, true);
}

// ── Upload endpoint ───────────────────────────────────────────────────────────

test('an authenticated user can upload', function (): void {
    $user = regularWithToken($this, 'api-uploader@example.com');

    $response = $this->withToken($user['token'])
        ->post('/api/v1/media', ['file' => apiPng()], ['Accept' => 'application/json']);

    $response->assertStatus(201)
        ->assertJsonPath('data.type', 'image')
        ->assertJsonPath('data.visibility', 'private');

    expect(MediaFile::query()->count())->toBe(1)
        ->and(MediaFile::query()->first()->uploaded_by)->toBe($user['user']->id);
});

test('uploading requires authentication', function (): void {
    $this->post('/api/v1/media', ['file' => apiPng()], ['Accept' => 'application/json'])
        ->assertStatus(401);

    expect(MediaFile::query()->count())->toBe(0);
});

test('a rejected file reports a machine readable reason and stores nothing', function (): void {
    $user = regularWithToken($this, 'api-rejected@example.com');

    $path = tempnam(sys_get_temp_dir(), 'alpha_bad_');
    file_put_contents($path, "<?php echo 'x'; ?>");
    $script = new UploadedFile($path, 'photo.png', 'image/png', null, true);

    $this->withToken($user['token'])
        ->post('/api/v1/media', ['file' => $script], ['Accept' => 'application/json'])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'MEDIA_REJECTED')
        ->assertJsonPath('error.details.reason', 'unsupported_type');

    expect(MediaFile::query()->count())->toBe(0)
        ->and(Storage::disk('local')->allFiles())->toBe([]);
});

test('the response never discloses the disk or the storage path', function (): void {
    $user = regularWithToken($this, 'api-nopath@example.com');

    $response = $this->withToken($user['token'])
        ->post('/api/v1/media', ['file' => apiPng()], ['Accept' => 'application/json']);

    $stored = MediaFile::query()->firstOrFail();

    expect($response->getContent())->not->toContain($stored->path)
        ->and($response->json('data'))->not->toHaveKey('disk')
        ->and($response->json('data'))->not->toHaveKey('path');
});

// ── Read endpoint ─────────────────────────────────────────────────────────────

test('an uploader can read their own private file', function (): void {
    $user = regularWithToken($this, 'api-owner@example.com');
    $media = $this->media->store(new MediaUpload(apiPng(), uploadedBy: $user['user']->id));

    $this->withToken($user['token'])
        ->getJson('/api/v1/media/'.$media->id)
        ->assertOk()
        ->assertJsonPath('data.id', $media->id);
});

test('a stranger gets 404 rather than 403, so ids cannot be probed', function (): void {
    $owner = regularWithToken($this, 'api-owner2@example.com');
    $media = $this->media->store(new MediaUpload(apiPng(), uploadedBy: $owner['user']->id));

    $stranger = regularWithToken($this, 'api-stranger@example.com');

    // Indistinguishable from a media id that was never issued: a 403 here would
    // confirm the file exists.
    $this->withToken($stranger['token'])
        ->getJson('/api/v1/media/'.$media->id)
        ->assertStatus(404)
        ->assertJsonPath('error.code', 'NOT_FOUND');
});

test('public media is readable by any authenticated caller', function (): void {
    $owner = regularWithToken($this, 'api-pubowner@example.com');
    $media = $this->media->store(new MediaUpload(apiPng(), MediaVisibility::PUBLIC, uploadedBy: $owner['user']->id));

    $other = regularWithToken($this, 'api-other@example.com');

    $this->withToken($other['token'])
        ->getJson('/api/v1/media/'.$media->id)
        ->assertOk()
        ->assertJsonPath('data.visibility', 'public');
});

// ── Admin surface ─────────────────────────────────────────────────────────────

test('an admin with media.view can list media', function (): void {
    $owner = regularWithToken($this, 'api-listed@example.com');
    $this->media->store(new MediaUpload(apiPng(), uploadedBy: $owner['user']->id));

    $admin = adminWithRoles($this, ['super_admin'], 'media-admin@example.com');

    $this->withToken($admin['token'])
        ->getJson('/api/v1/admin/media')
        ->assertOk()
        ->assertJsonPath('success', true);
});

test('an admin without media.view is refused', function (): void {
    $admin = adminWithRoles($this, ['support'], 'weak-media-admin@example.com');

    $this->withToken($admin['token'])
        ->getJson('/api/v1/admin/media')
        ->assertStatus(403)
        ->assertJsonPath('error.code', 'PERMISSION_DENIED');
});

test('a regular user cannot reach the media admin surface', function (): void {
    $user = regularWithToken($this, 'api-notadmin@example.com');

    $this->withToken($user['token'])->getJson('/api/v1/admin/media')->assertStatus(403);
});

test('an admin without media.delete cannot remove media', function (): void {
    $owner = regularWithToken($this, 'api-keep@example.com');
    $media = $this->media->store(new MediaUpload(apiPng(), uploadedBy: $owner['user']->id));

    $admin = adminWithRoles($this, ['editor'], 'nodelete-admin@example.com');

    $this->withToken($admin['token'])
        ->deleteJson('/api/v1/admin/media/'.$media->id)
        ->assertStatus(403);

    expect(MediaFile::query()->find($media->id))->not->toBeNull();
});

test('admin deletion soft deletes and leaves the bytes for the purge job', function (): void {
    $owner = regularWithToken($this, 'api-del@example.com');
    $media = $this->media->store(new MediaUpload(apiPng(), uploadedBy: $owner['user']->id));
    $path = $media->refresh()->path;

    $admin = adminWithRoles($this, ['super_admin'], 'del-admin@example.com');

    $this->withToken($admin['token'])
        ->deleteJson('/api/v1/admin/media/'.$media->id)
        ->assertOk();

    expect(MediaFile::query()->find($media->id))->toBeNull()
        ->and(MediaFile::withTrashed()->find($media->id))->not->toBeNull()
        ->and(Storage::disk('local')->exists($path))->toBeTrue();
});

test('the admin listing does not disclose storage paths either', function (): void {
    $owner = regularWithToken($this, 'api-adminpath@example.com');
    $media = $this->media->store(new MediaUpload(apiPng(), uploadedBy: $owner['user']->id));

    $admin = adminWithRoles($this, ['super_admin'], 'path-admin@example.com');

    $response = $this->withToken($admin['token'])->getJson('/api/v1/admin/media');

    expect($response->getContent())->not->toContain($media->refresh()->path);
});
