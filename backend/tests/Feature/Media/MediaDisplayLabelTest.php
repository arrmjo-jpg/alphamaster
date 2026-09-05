<?php

declare(strict_types=1);

use App\Modules\Localization\Database\Seeders\LanguageSeeder;
use App\Modules\Media\Enums\MediaStatus;
use App\Modules\Media\Enums\MediaType;
use App\Modules\Media\Enums\MediaVisibility;
use App\Modules\Media\Enums\ScanStatus;
use App\Modules\Media\Models\MediaFile;
use App\Modules\Media\Resources\MediaAdminResource;
use App\Modules\Media\Resources\MediaResource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    // The container runs against Redis, where a cache entry outlives a test.
    Cache::flush();

    $this->seed(LanguageSeeder::class);
});

/**
 * A stored record with one case chosen from each labelled enum.
 */
function labelledMedia(): MediaFile
{
    $media = new MediaFile(['collection' => 'default', 'original_filename' => 'labelled.png']);

    $media->forceFill([
        'disk' => 'public',
        'path' => 'media/labelled/labelled.png',
        'type' => MediaType::IMAGE,
        'mime_type' => 'image/png',
        'extension' => 'png',
        'size_bytes' => 512,
        'checksum' => str_repeat('c', 64),
        'visibility' => MediaVisibility::PRIVATE,
        'status' => MediaStatus::UPLOADED,
        'scan_status' => ScanStatus::NOT_SCANNED,
        'width' => 10,
        'height' => 10,
    ]);
    $media->save();

    return $media->refresh();
}

/** The four fields this change labels, and nothing else. */
const LABELLED_FIELDS = ['type', 'visibility', 'status', 'scan_status'];

// ── The technical values are untouched ───────────────────────────────────────

test('every enum keeps its raw backed value beside the label', function (): void {
    $media = labelledMedia();

    foreach (['en', 'ar'] as $locale) {
        app()->setLocale($locale);

        foreach ([new MediaAdminResource($media), new MediaResource($media, null)] as $resource) {
            $payload = $resource->toArray(request());

            expect($payload['type'])->toBe('image')
                ->and($payload['visibility'])->toBe('private')
                ->and($payload['status'])->toBe('uploaded')
                ->and($payload['scan_status'])->toBe('not_scanned');
        }
    }
});

test('a value is never replaced by its label', function (): void {
    app()->setLocale('ar');

    $payload = (new MediaAdminResource(labelledMedia()))->toArray(request());

    foreach (LABELLED_FIELDS as $field) {
        expect($payload[$field])->toBeString()
            ->and($payload[$field])->not->toBe($payload[$field.'_label']);
    }
});

// ── The labels ───────────────────────────────────────────────────────────────

test('each label reads in English', function (): void {
    app()->setLocale('en');

    $payload = (new MediaAdminResource(labelledMedia()))->toArray(request());

    expect($payload['type_label'])->toBe('Image')
        ->and($payload['visibility_label'])->toBe('Private')
        ->and($payload['status_label'])->toBe('Uploaded')
        ->and($payload['scan_status_label'])->toBe('Not Scanned');
});

test('each label reads in Arabic', function (): void {
    app()->setLocale('ar');

    $payload = (new MediaAdminResource(labelledMedia()))->toArray(request());

    expect($payload['type_label'])->toBe('صورة')
        ->and($payload['visibility_label'])->toBe('خاص')
        ->and($payload['status_label'])->toBe('تم الرفع')
        ->and($payload['scan_status_label'])->toBe('لم يُفحص');
});

test('the public resource carries the same labels as the admin one', function (): void {
    $media = labelledMedia();

    foreach (['en', 'ar'] as $locale) {
        app()->setLocale($locale);

        $admin = (new MediaAdminResource($media))->toArray(request());
        $public = (new MediaResource($media, null))->toArray(request());

        foreach (LABELLED_FIELDS as $field) {
            expect($public[$field.'_label'])->toBe($admin[$field.'_label'], $field.' in '.$locale);
        }
    }
});

test('a label follows the request locale rather than the record', function (): void {
    // ADR 0031 rule 2: the same record reads differently to two callers and
    // identically to the code.
    $media = labelledMedia();

    app()->setLocale('en');
    $english = (new MediaResource($media, null))->toArray(request());

    app()->setLocale('ar');
    $arabic = (new MediaResource($media, null))->toArray(request());

    foreach (LABELLED_FIELDS as $field) {
        expect($arabic[$field.'_label'])->not->toBe($english[$field.'_label'], $field)
            ->and($arabic[$field])->toBe($english[$field], $field.' value moved');
    }
});

test('every case of every labelled enum resolves in both locales', function (): void {
    $enums = [MediaType::class, MediaVisibility::class, MediaStatus::class, ScanStatus::class];
    $checked = 0;

    foreach (['en', 'ar'] as $locale) {
        app()->setLocale($locale);

        foreach ($enums as $enum) {
            foreach ($enum::cases() as $case) {
                expect($case->label())->not->toBe($case->value, $enum.'::'.$case->value.' in '.$locale);
                $checked++;
            }
        }
    }

    expect($checked)->toBe(34);
});

// ── The field sets stay apart ────────────────────────────────────────────────

test('the admin and public resources expose exactly their own fields', function (): void {
    $media = labelledMedia();

    $admin = array_keys((new MediaAdminResource($media))->toArray(request()));
    $public = array_keys((new MediaResource($media, null))->toArray(request()));

    expect($admin)->toBe([
        'id', 'collection', 'original_filename', 'mime_type',
        'type', 'type_label', 'size_bytes', 'checksum',
        'visibility', 'visibility_label', 'status', 'status_label',
        'scan_status', 'scan_status_label',
        'failure_reason', 'attachable_type', 'attachable_id', 'uploaded_by', 'created_at',
    ]);

    expect($public)->toBe([
        'id', 'collection', 'original_filename', 'mime_type',
        'type', 'type_label', 'size_bytes', 'checksum',
        'visibility', 'visibility_label', 'status', 'status_label',
        'scan_status', 'scan_status_label',
        'width', 'height', 'duration_seconds', 'url', 'created_at',
    ]);
});

test('the operator fields never appear on the public resource', function (): void {
    // The separation this refactor preserved: an operator's view of a record
    // must not reach a public caller because someone reused a class.
    $public = (new MediaResource(labelledMedia(), null))->toArray(request());

    foreach (['failure_reason', 'attachable_type', 'attachable_id', 'uploaded_by'] as $field) {
        expect($public)->not->toHaveKey($field);
    }
});

test('neither resource exposes the storage layout', function (): void {
    $media = labelledMedia();

    foreach ([new MediaAdminResource($media), new MediaResource($media, null)] as $resource) {
        $encoded = (string) json_encode($resource->toArray(request()));

        expect($encoded)->not->toContain('media/labelled/labelled.png')
            ->and($encoded)->not->toContain('"disk"')
            ->and($encoded)->not->toContain('"path"');
    }
});

test('this change adds four fields to each resource and no others', function (): void {
    $media = labelledMedia();

    $admin = (new MediaAdminResource($media))->toArray(request());
    $public = (new MediaResource($media, null))->toArray(request());

    // 15 before this change, 19 after.
    expect($admin)->toHaveCount(19)
        ->and($public)->toHaveCount(19);

    $added = array_values(array_filter(
        array_keys($admin),
        static fn (string $key): bool => str_ends_with($key, '_label')
    ));

    expect($added)->toBe(['type_label', 'visibility_label', 'status_label', 'scan_status_label'])
        ->and(array_values(array_filter(
            array_keys($public),
            static fn (string $key): bool => str_ends_with($key, '_label')
        )))->toBe($added);
});
