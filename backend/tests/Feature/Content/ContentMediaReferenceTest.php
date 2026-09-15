<?php

declare(strict_types=1);

use App\Modules\Authorization\Database\Seeders\AdminPermissionSeeder;
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
 * Content refers to media by id, through Core's MediaReferenceContract (ADR 0055 §10,
 * ADR 0057 §4): a team member's picture and a page's or member's sharing image. One rule for
 * every reference — a public image that is ready to serve — and no upload path of their own.
 */

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

/**
 * A stored image, ready by default. Written directly: what is under test is the reference
 * rule, not the intake pipeline, which has tests of its own.
 *
 * @param  array<string, mixed>  $overrides
 */
function referencedImage(array $overrides = []): string
{
    $file = new MediaFile;
    $file->forceFill(array_merge([
        'collection' => 'content',
        'disk' => 'local',
        'path' => 'media/'.uniqid('ref_', true).'.png',
        'original_filename' => 'picture.png',
        'mime_type' => 'image/png',
        'extension' => 'png',
        'type' => MediaType::IMAGE,
        'size_bytes' => 68,
        'checksum' => str_repeat('a', 64),
        'visibility' => MediaVisibility::PUBLIC,
        'status' => MediaStatus::READY,
        'scan_status' => ScanStatus::NOT_SCANNED,
        'width' => 4,
        'height' => 4,
    ], $overrides))->save();

    return $file->id;
}

function referenceTeamToken(): string
{
    return tokenWithPermissions([TeamPermission::VIEW->value, TeamPermission::CREATE->value, TeamPermission::UPDATE->value]);
}

function referencePagesToken(): string
{
    return tokenWithPermissions([PagePermission::VIEW->value, PagePermission::CREATE->value, PagePermission::UPDATE->value]);
}

function referenceMember(mixed $test, string $token): string
{
    return (string) $test->withToken($token)->postJson('/api/v1/admin/team', [])->assertCreated()->json('data.id');
}

function referencePage(mixed $test, string $token): string
{
    return (string) $test->withToken($token)->postJson('/api/v1/admin/pages', ['sort_order' => 0])->assertCreated()->json('data.id');
}

/** Every way an id can fail to be a public image that is ready to serve. */
dataset('unusable images', [
    'private' => [fn (): string => referencedImage(['visibility' => MediaVisibility::PRIVATE])],
    'still processing' => [fn (): string => referencedImage(['status' => MediaStatus::PROCESSING])],
    'failed processing' => [fn (): string => referencedImage(['status' => MediaStatus::PROCESSING_FAILED])],
    'infected' => [fn (): string => referencedImage(['scan_status' => ScanStatus::INFECTED])],
    'not an image' => [fn (): string => referencedImage(['type' => MediaType::DOCUMENT, 'mime_type' => 'application/pdf'])],
    'unknown' => [fn (): string => '01J00000000000000000000000'],
]);

test('a member takes a ready public image as their picture, resolved in the admin read', function (): void {
    $token = referenceTeamToken();
    $id = referenceMember($this, $token);
    $image = referencedImage();

    $this->withToken($token)->patchJson("/api/v1/admin/team/{$id}", ['avatar_media_id' => $image])
        ->assertOk()
        ->assertJsonPath('data.avatar_media_id', $image)
        ->assertJsonPath('data.avatar.id', $image)
        ->assertJsonPath('data.avatar.mime_type', 'image/png');

    expect($this->withToken($token)->getJson('/api/v1/admin/team')->json('data.0.avatar.url'))->toBeString();
});

test('a picture that is not a ready public image is refused, and the member keeps the one it had', function (Closure $unusable): void {
    $token = referenceTeamToken();
    $id = referenceMember($this, $token);
    $kept = referencedImage();

    $this->withToken($token)->patchJson("/api/v1/admin/team/{$id}", ['avatar_media_id' => $kept])->assertOk();

    $this->withToken($token)->patchJson("/api/v1/admin/team/{$id}", ['avatar_media_id' => $unusable()])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'CONTENT_IMAGE_UNAVAILABLE');

    expect($this->withToken($token)->getJson('/api/v1/admin/team')->json('data.0.avatar_media_id'))->toBe($kept);
})->with('unusable images');

test('a picture is removed by sending null, and nothing is deleted from the library', function (): void {
    $token = referenceTeamToken();
    $id = referenceMember($this, $token);
    $image = referencedImage();

    $this->withToken($token)->patchJson("/api/v1/admin/team/{$id}", ['avatar_media_id' => $image])->assertOk();

    $this->withToken($token)->patchJson("/api/v1/admin/team/{$id}", ['avatar_media_id' => null])
        ->assertOk()
        ->assertJsonPath('data.avatar_media_id', null)
        ->assertJsonPath('data.avatar', null);

    // The reference went; the file is the library's, and may be used elsewhere.
    expect(MediaFile::query()->find($image))->not->toBeNull();
});

test('a page\'s sharing image is set per language, from a ready public image', function (): void {
    $token = referencePagesToken();
    $id = referencePage($this, $token);
    $image = referencedImage();

    $this->withToken($token)->putJson("/api/v1/admin/pages/{$id}/translations/en", [
        'title' => 'Privacy policy',
        'seo' => ['og_media_id' => $image],
    ])->assertOk()->assertJsonPath('data.seo.en.og_media_id', $image);
});

test('a page\'s sharing image that is not a ready public image is refused', function (Closure $unusable): void {
    $token = referencePagesToken();
    $id = referencePage($this, $token);

    $this->withToken($token)->putJson("/api/v1/admin/pages/{$id}/translations/en", [
        'seo' => ['og_media_id' => $unusable()],
    ])->assertStatus(422)->assertJsonPath('error.code', 'CONTENT_IMAGE_UNAVAILABLE');
})->with('unusable images');

test('a member\'s sharing image follows the same rule', function (): void {
    $token = referenceTeamToken();
    $id = referenceMember($this, $token);

    $this->withToken($token)->putJson("/api/v1/admin/team/{$id}/translations/en", [
        'seo' => ['og_media_id' => referencedImage(['visibility' => MediaVisibility::PRIVATE])],
    ])->assertStatus(422)->assertJsonPath('error.code', 'CONTENT_IMAGE_UNAVAILABLE');

    $image = referencedImage();

    $this->withToken($token)->putJson("/api/v1/admin/team/{$id}/translations/en", [
        'seo' => ['og_media_id' => $image],
    ])->assertOk()->assertJsonPath('data.seo.en.og_media_id', $image);
});

test('a reference needs the content permission, not a media one', function (): void {
    // Choosing a picture for a member is editing the member. It does not need `media.view`,
    // and a token without `team.update` is refused before any media is looked at.
    $readOnly = tokenWithPermissions([TeamPermission::VIEW->value, TeamPermission::CREATE->value]);
    $id = referenceMember($this, $readOnly);

    $this->withToken($readOnly)->patchJson("/api/v1/admin/team/{$id}", ['avatar_media_id' => referencedImage()])
        ->assertForbidden();
});
