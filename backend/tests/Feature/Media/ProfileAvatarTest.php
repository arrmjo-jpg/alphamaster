<?php

declare(strict_types=1);

use App\Modules\Media\Models\MediaFile;
use App\Modules\Settings\Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

/*
 * The signed-in account's profile picture (ADR 0051 §4): ordinary public media in the
 * `avatar` collection, one current at a time, read back through the profile.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Cache::flush();
    config(['queue.default' => 'sync']);
    Storage::fake('local');
    $this->seed(SettingSeeder::class);
});

/**
 * A real, decodable PNG over a real temp file — not UploadedFile::fake(), whose type
 * comes from the name the media validator exists to disbelieve.
 */
function avatarPng(string $name = 'me.png'): UploadedFile
{
    $path = tempnam(sys_get_temp_dir(), 'alpha_avatar_');
    file_put_contents($path, base64_decode(
        'iVBORw0KGgoAAAANSUhEUgAAAAQAAAAECAYAAACp8Z5+AAAAFUlEQVR42mNk'
        .'+M+ACzDhVQGVUEsBAP7bA/1B9AAAAABJRU5ErkJggg=='
    ));

    return new UploadedFile($path, $name, 'image/png', null, true);
}

function avatarScript(): UploadedFile
{
    $path = tempnam(sys_get_temp_dir(), 'alpha_avatar_');
    file_put_contents($path, "<?php echo 'pwned'; ?>\n");

    return new UploadedFile($path, 'me.png', 'image/png', null, true);
}

test('a picture is set, public, attached to the account, and shown on the profile', function (): void {
    $user = makeAccount(['email' => 'avatar-owner@example.test']);
    $token = $user->createToken('session', ['user:access'])->plainTextToken;

    $this->withToken($token)->post('/api/v1/profile/avatar', ['file' => avatarPng()], ['Accept' => 'application/json'])
        ->assertStatus(201);

    $media = MediaFile::query()->where('collection', 'avatar')->sole();

    expect($media->attachable_id)->toBe($user->id)
        ->and($media->visibility->value)->toBe('public');

    $this->withToken($token)->getJson('/api/v1/profile')->assertOk();
});

test('a new picture retires the previous one', function (): void {
    $user = makeAccount(['email' => 'avatar-replacer@example.test']);
    $token = $user->createToken('session', ['user:access'])->plainTextToken;

    $this->withToken($token)->post('/api/v1/profile/avatar', ['file' => avatarPng('one.png')], ['Accept' => 'application/json'])->assertStatus(201);
    $this->withToken($token)->post('/api/v1/profile/avatar', ['file' => avatarPng('two.png')], ['Accept' => 'application/json'])->assertStatus(201);

    expect(MediaFile::query()->where('collection', 'avatar')->count())->toBe(1)
        ->and(MediaFile::onlyTrashed()->where('collection', 'avatar')->count())->toBe(1)
        ->and(MediaFile::query()->where('collection', 'avatar')->sole()->original_filename)->toBe('two.png');
});

test('something that is not an image is refused and the current picture stays', function (): void {
    $user = makeAccount(['email' => 'avatar-keeper@example.test']);
    $token = $user->createToken('session', ['user:access'])->plainTextToken;

    $this->withToken($token)->post('/api/v1/profile/avatar', ['file' => avatarPng()], ['Accept' => 'application/json'])->assertStatus(201);

    $this->withToken($token)->post('/api/v1/profile/avatar', ['file' => avatarScript()], ['Accept' => 'application/json'])
        ->assertStatus(422);

    expect(MediaFile::query()->where('collection', 'avatar')->count())->toBe(1);
});

test('the picture is removed, and removing none is not an error', function (): void {
    $user = makeAccount(['email' => 'avatar-remover@example.test']);
    $token = $user->createToken('session', ['user:access'])->plainTextToken;

    $this->withToken($token)->deleteJson('/api/v1/profile/avatar')->assertNoContent();

    $this->withToken($token)->post('/api/v1/profile/avatar', ['file' => avatarPng()], ['Accept' => 'application/json'])->assertStatus(201);
    $this->withToken($token)->deleteJson('/api/v1/profile/avatar')->assertNoContent();

    expect(MediaFile::query()->where('collection', 'avatar')->count())->toBe(0)
        ->and($this->withToken($token)->getJson('/api/v1/profile')->json('data.avatar_url'))->toBeNull();
});

test('setting a picture needs a signed-in account', function (): void {
    $this->post('/api/v1/profile/avatar', ['file' => avatarPng()], ['Accept' => 'application/json'])->assertStatus(401);
});
