<?php

declare(strict_types=1);

use App\Modules\Authorization\Database\Seeders\AdminPermissionSeeder;
use App\Modules\Media\Contracts\MediaAccessPolicyContract;
use App\Modules\Media\Contracts\MediaScannerContract;
use App\Modules\Media\Contracts\MediaServiceContract;
use App\Modules\Media\Contracts\MediaStorageContract;
use App\Modules\Media\Data\MediaUpload;
use App\Modules\Media\Enums\MediaStatus;
use App\Modules\Media\Enums\MediaType;
use App\Modules\Media\Enums\MediaVisibility;
use App\Modules\Media\Enums\ScanStatus;
use App\Modules\Media\Exceptions\MediaValidationException;
use App\Modules\Media\Jobs\ProcessMediaFile;
use App\Modules\Media\Jobs\PurgeDeletedMedia;
use App\Modules\Media\Jobs\ScanMediaFile;
use App\Modules\Media\Models\MediaFile;
use App\Modules\Media\Services\MediaAccessResolver;
use App\Modules\Media\Services\ProcessorRegistry;
use App\Modules\Media\Services\UploadValidator;
use App\Modules\Settings\Contracts\SettingServiceInterface;
use App\Modules\Settings\Database\Seeders\SettingSeeder;
use App\Modules\User\Enums\AccountType;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Cache::flush();

    // The intake pipeline is queued. The container exports QUEUE_CONNECTION=redis and
    // a real environment variable beats phpunit.xml (ADR 0027), so without this the
    // jobs would go to Redis and never run, and assertions about what the pipeline
    // produced would be measuring nothing. That the jobs are queued at all, and on
    // which queue, is asserted separately with Queue::fake().
    config(['queue.default' => 'sync']);

    Storage::fake('local');

    $this->seed(SettingSeeder::class);
    $this->seed(AdminPermissionSeeder::class);

    $this->media = app(MediaServiceContract::class);
    $this->validator = app(UploadValidator::class);

    $this->user = makeAccount([
        'name' => 'Uploader',
        'email' => 'uploader@example.com',
        'account_type' => AccountType::USER,
    ]);
});

/**
 * A genuine UploadedFile over a real temp file.
 *
 * Deliberately not UploadedFile::fake(): the fake overrides getMimeType() to guess
 * from the filename, which is precisely the claim the validator exists to disbelieve.
 * Testing against it would prove the validator rejects files the test double already
 * mislabelled, and would pass just as happily if the validator trusted the client.
 *
 * The claimed type is passed separately so a test can lie about it the way a hostile
 * client would.
 */
function realUpload(string $name, string $bytes, string $claimedMime = 'application/octet-stream'): UploadedFile
{
    $path = tempnam(sys_get_temp_dir(), 'alpha_media_');
    file_put_contents($path, $bytes);

    return new UploadedFile($path, $name, $claimedMime, null, true);
}

/**
 * A real, decodable 4x4 PNG.
 */
function pngFile(string $name = 'photo.png'): UploadedFile
{
    return realUpload($name, base64_decode(
        'iVBORw0KGgoAAAANSUhEUgAAAAQAAAAECAYAAACp8Z5+AAAAFUlEQVR42mNk'
        .'+M+ACzDhVQGVUEsBAP7bA/1B9AAAAABJRU5ErkJggg=='
    ), 'image/png');
}

/**
 * Bytes that are a script, whatever the name and the claimed type say.
 */
function scriptFile(string $name): UploadedFile
{
    return realUpload($name, "<?php echo 'pwned'; ?>\n", 'image/png');
}

// ── Validation: the name is a claim, the bytes are the fact ───────────────────

test('a genuine image is accepted', function (): void {
    $media = $this->media->store(new MediaUpload(pngFile(), uploadedBy: $this->user->id));

    expect($media->mime_type)->toBe('image/png')
        ->and($media->type)->toBe(MediaType::IMAGE)
        ->and($media->checksum)->toHaveLength(64);
});

test('a script renamed as an image is refused', function (): void {
    // The client says photo.png; the bytes say otherwise, and disagreement is the
    // whole signal.
    expect(fn () => $this->media->store(new MediaUpload(scriptFile('photo.png'))))
        ->toThrow(MediaValidationException::class);

    expect(MediaFile::query()->count())->toBe(0);
});

test('an executable extension is refused outright', function (): void {
    foreach (['payload.php', 'payload.phar', 'payload.exe', 'payload.sh', 'payload.js'] as $name) {
        expect(fn () => $this->media->store(new MediaUpload(scriptFile($name))))
            ->toThrow(MediaValidationException::class);
    }

    expect(MediaFile::query()->count())->toBe(0);
});

test('a traversal filename cannot influence where the file is stored', function (): void {
    // Symfony basenames the client filename before anything here sees it, so the
    // validator's traversal guard is defence in depth rather than the only barrier.
    // The property is what matters, so the property is what is asserted: whatever the
    // client calls a file, the stored path stays inside the directory Media chose.
    $media = $this->media->store(new MediaUpload(
        pngFile('../../../etc/passwd.png'),
        uploadedBy: $this->user->id
    ));

    expect($media->path)->not->toContain('..')
        ->and($media->path)->not->toContain('etc/passwd')
        ->and($media->path)->toStartWith('private/image/')
        ->and($media->original_filename)->not->toContain('..')
        ->and(Storage::disk('local')->exists($media->path))->toBeTrue();
});

test('the validator rejects filenames carrying separators or control characters', function (): void {
    // Exercised directly, because a real request cannot deliver such a name past
    // Symfony. The guard still has to hold for any caller that builds an upload
    // itself instead of receiving one from a request.
    $method = new ReflectionMethod($this->validator, 'assertFilenameIsSafe');

    foreach (['../../etc/passwd.png', 'a/b.png', 'a\\b.png', "bad\0name.png", '', str_repeat('a', 300)] as $name) {
        $rejected = false;

        try {
            $method->invoke($this->validator, $name);
        } catch (MediaValidationException) {
            $rejected = true;
        }

        expect($rejected)->toBeTrue('the filename should have been rejected');
    }
});

test('an unsupported content type is refused even with an allowed extension', function (): void {
    // .txt is an accepted extension, but a JPEG body under it disagrees.
    $file = realUpload('notes.txt', base64_decode('/9j/4AAQSkZJRgABAQEAYABgAAD/2wBDAAgGBgcGBQgHBwcJCQgKDBQNDAsLDBkSEw8UHRofHh0aHBwgJC4nICIsIxwcKDcpLDAxNDQ0Hyc5PTgyPC4zNDL/wAALCAABAAEBAREA/8QAFAABAAAAAAAAAAAAAAAAAAAACf/EABQQAQAAAAAAAAAAAAAAAAAAAAD/2gAIAQEAAD8AKp//2Q=='), 'text/plain');

    expect(fn () => $this->validator->validate($file))->toThrow(MediaValidationException::class);
});

test('an empty file is refused', function (): void {
    expect(fn () => $this->validator->validate(realUpload('empty.png', '', 'image/png')))
        ->toThrow(MediaValidationException::class);
});

test('nothing reaches storage when validation fails', function (): void {
    try {
        $this->media->store(new MediaUpload(scriptFile('evil.php')));
    } catch (MediaValidationException) {
        // expected
    }

    expect(Storage::disk('local')->allFiles())->toBe([]);
});

// ── Storage: the client's filename never becomes a path ───────────────────────

test('the stored name is generated, not taken from the client', function (): void {
    $media = $this->media->store(new MediaUpload(pngFile('my holiday photo.png'), uploadedBy: $this->user->id));

    expect($media->path)->not->toContain('my holiday photo')
        ->and($media->original_filename)->toBe('my holiday photo.png')
        ->and(Storage::disk('local')->exists($media->path))->toBeTrue();
});

test('the disk and path are never serialised', function (): void {
    $media = $this->media->store(new MediaUpload(pngFile(), uploadedBy: $this->user->id));

    expect($media->toArray())->not->toHaveKey('disk')
        ->and($media->toArray())->not->toHaveKey('path')
        ->and(json_encode($media))->not->toContain($media->path);
});

// ── The intake pipeline ───────────────────────────────────────────────────────

test('intake is queued on the media queue rather than run inline', function (): void {
    Queue::fake();

    $this->media->store(new MediaUpload(pngFile(), uploadedBy: $this->user->id));

    Queue::assertPushed(ScanMediaFile::class, fn (ScanMediaFile $job): bool => $job->queue === 'media');
});

test('a file passes through scanning and processing to ready', function (): void {
    $media = $this->media->store(new MediaUpload(pngFile(), uploadedBy: $this->user->id));

    expect($media->refresh()->status)->toBe(MediaStatus::READY)
        // No antivirus exists here, and the platform says so rather than claiming clean.
        ->and($media->scan_status)->toBe(ScanStatus::NOT_SCANNED);
});

test('image dimensions are derived without gd or imagick', function (): void {
    $media = $this->media->store(new MediaUpload(pngFile(), uploadedBy: $this->user->id));

    expect($media->refresh()->width)->toBe(4)
        ->and($media->height)->toBe(4)
        ->and($media->metadata['dimensions_available'])->toBeTrue();
});

test('a type with no processor is reported as undetermined rather than zeroed', function (): void {
    $media = $this->media->store(new MediaUpload(
        realUpload('doc.txt', "hello\n", 'text/plain'),
        uploadedBy: $this->user->id
    ));

    expect($media->refresh()->status)->toBe(MediaStatus::READY)
        ->and($media->width)->toBeNull()
        // Unknown is recorded as unknown, not as zero pixels.
        ->and($media->metadata['dimensions_available'])->toBeFalse();
});

test('the scan job is idempotent under retry', function (): void {
    $media = $this->media->store(new MediaUpload(pngFile(), uploadedBy: $this->user->id));
    $before = $media->refresh()->status;

    (new ScanMediaFile($media->id))->handle(app(MediaScannerContract::class));

    expect($media->refresh()->status)->toBe($before)->toBe(MediaStatus::READY);
});

test('a job for media deleted before it ran does nothing', function (): void {
    $media = $this->media->store(new MediaUpload(pngFile(), uploadedBy: $this->user->id));
    $id = $media->id;
    $media->forceDelete();

    (new ScanMediaFile($id))->handle(app(MediaScannerContract::class));
    (new ProcessMediaFile($id))->handle(app(ProcessorRegistry::class));

    expect(MediaFile::withTrashed()->find($id))->toBeNull();
});

test('an infected file is marked failed and never becomes servable', function (): void {
    app()->bind(MediaScannerContract::class, fn () => new class implements MediaScannerContract
    {
        public function scan(MediaFile $media): ScanStatus
        {
            return ScanStatus::INFECTED;
        }

        public function name(): string
        {
            return 'test-infected';
        }
    });

    $media = $this->media->store(new MediaUpload(pngFile(), uploadedBy: $this->user->id));

    expect($media->refresh()->status)->toBe(MediaStatus::SCAN_FAILED)
        ->and($media->scan_status)->toBe(ScanStatus::INFECTED)
        ->and($media->isServable())->toBeFalse()
        ->and($this->media->urlFor($media, $this->user))->toBeNull();
});

// ── Access control ────────────────────────────────────────────────────────────

test('public media yields a URL to anyone', function (): void {
    $media = $this->media->store(new MediaUpload(pngFile(), MediaVisibility::PUBLIC, uploadedBy: $this->user->id));

    expect($this->media->urlFor($media->refresh(), null))->toBeString();
});

test('private media is denied to a stranger and allowed to its uploader', function (): void {
    $stranger = makeAccount(['email' => 'stranger@example.com', 'account_type' => AccountType::USER]);
    $media = $this->media->store(new MediaUpload(pngFile(), MediaVisibility::PRIVATE, uploadedBy: $this->user->id));
    $media->refresh();

    $access = app(MediaAccessResolver::class);

    expect($access->allows($media, $stranger))->toBeFalse()
        ->and($access->allows($media, null))->toBeFalse()
        ->and($access->allows($media, $this->user))->toBeTrue();
});

test('private media attached to a type with no policy is denied, failing closed', function (): void {
    $media = $this->media->store(new MediaUpload(pngFile(), MediaVisibility::PRIVATE, uploadedBy: $this->user->id));

    // Attached to something whose module registered no policy: nobody has said who
    // may see it, which is not the same as everybody.
    $media->refresh()->forceFill([
        'attachable_type' => 'App\\Modules\\Nonexistent\\Models\\Thing',
        'attachable_id' => (string) Str::ulid(),
    ])->save();

    expect(app(MediaAccessResolver::class)->allows($media->refresh(), $this->user))->toBeFalse();
});

test('a registered policy decides access for its own type', function (): void {
    $media = $this->media->store(new MediaUpload(pngFile(), MediaVisibility::PRIVATE, uploadedBy: $this->user->id));
    $media->refresh()->forceFill([
        'attachable_type' => 'Thing',
        'attachable_id' => (string) Str::ulid(),
    ])->save();

    $resolver = app(MediaAccessResolver::class);
    $resolver->register(new class implements MediaAccessPolicyContract
    {
        public function appliesTo(): string
        {
            return 'Thing';
        }

        public function allows(MediaFile $media, ?object $viewer): bool
        {
            return $viewer !== null;
        }
    });

    expect($resolver->allows($media->refresh(), $this->user))->toBeTrue()
        ->and($resolver->allows($media->refresh(), null))->toBeFalse();
});

test('an unready file is served to nobody, whatever the policy says', function (): void {
    $media = $this->media->store(new MediaUpload(pngFile(), MediaVisibility::PUBLIC, uploadedBy: $this->user->id));
    $media->refresh()->forceFill(['status' => MediaStatus::PROCESSING])->save();

    expect(app(MediaAccessResolver::class)->allows($media->refresh(), $this->user))->toBeFalse();
});

// ── CDN ───────────────────────────────────────────────────────────────────────

test('with no CDN configured the storage URL is returned unchanged', function (): void {
    $media = $this->media->store(new MediaUpload(pngFile(), MediaVisibility::PUBLIC, uploadedBy: $this->user->id));
    $url = $this->media->urlFor($media->refresh(), null);

    expect($url)->toBeString()->not->toContain('cdn.example.com');
});

test('a configured CDN rewrites public URLs but never private ones', function (): void {
    app(SettingServiceInterface::class)->clearCache();
    DB::table('settings')->insert([
        ['id' => (string) Str::ulid(), 'group' => 'cdn', 'key' => 'enabled', 'value' => 'true', 'type' => 'boolean', 'is_secret' => false, 'is_public' => true, 'created_at' => now(), 'updated_at' => now()],
        ['id' => (string) Str::ulid(), 'group' => 'cdn', 'key' => 'base_url', 'value' => 'https://cdn.example.com', 'type' => 'string', 'is_secret' => false, 'is_public' => true, 'created_at' => now(), 'updated_at' => now()],
    ]);
    app(SettingServiceInterface::class)->clearCache();

    $public = $this->media->store(new MediaUpload(pngFile(), MediaVisibility::PUBLIC, uploadedBy: $this->user->id));

    expect($this->media->urlFor($public->refresh(), null))->toStartWith('https://cdn.example.com/');

    // Private URLs are signed and expiring; putting a shared cache in front of a
    // credential-bearing URL is how private files stop being private.
    $private = $this->media->store(new MediaUpload(pngFile(), MediaVisibility::PRIVATE, uploadedBy: $this->user->id));
    $privateUrl = $this->media->urlFor($private->refresh(), $this->user);

    expect($privateUrl === null || ! str_contains($privateUrl, 'cdn.example.com'))->toBeTrue();
});

// ── Retention ─────────────────────────────────────────────────────────────────

test('deleting soft deletes the record and leaves the bytes in place', function (): void {
    $media = $this->media->store(new MediaUpload(pngFile(), uploadedBy: $this->user->id));
    $path = $media->refresh()->path;

    $this->media->delete($media);

    expect(MediaFile::query()->find($media->id))->toBeNull()
        ->and(MediaFile::withTrashed()->find($media->id))->not->toBeNull()
        // The bytes outlive the row until an explicit purge, so a mistake is recoverable.
        ->and(Storage::disk('local')->exists($path))->toBeTrue();
});

test('the purge job removes bytes only after the retention window', function (): void {
    $media = $this->media->store(new MediaUpload(pngFile(), uploadedBy: $this->user->id));
    $path = $media->refresh()->path;
    $this->media->delete($media);

    (new PurgeDeletedMedia(30))->handle(app(MediaStorageContract::class));

    expect(Storage::disk('local')->exists($path))->toBeTrue();

    MediaFile::withTrashed()->find($media->id)->forceFill(['deleted_at' => now()->subDays(31)])->save();

    (new PurgeDeletedMedia(30))->handle(app(MediaStorageContract::class));

    expect(Storage::disk('local')->exists($path))->toBeFalse()
        ->and(MediaFile::withTrashed()->find($media->id))->toBeNull();
});

test('the purge job is retry safe when bytes are already gone', function (): void {
    $media = $this->media->store(new MediaUpload(pngFile(), uploadedBy: $this->user->id));
    $path = $media->refresh()->path;
    $this->media->delete($media);
    MediaFile::withTrashed()->find($media->id)->forceFill(['deleted_at' => now()->subDays(31)])->save();

    Storage::disk('local')->delete($path);

    // Already absent counts as purged; a second run must converge, not fail.
    (new PurgeDeletedMedia(30))->handle(app(MediaStorageContract::class));

    expect(MediaFile::withTrashed()->find($media->id))->toBeNull();
});

// ── Database invariants ───────────────────────────────────────────────────────

test('the database rejects unknown enum values on raw writes', function (): void {
    $base = [
        'id' => (string) Str::ulid(), 'collection' => 'default', 'disk' => 'local',
        'path' => 'x/y.png', 'original_filename' => 'y.png', 'mime_type' => 'image/png',
        'type' => 'image', 'size_bytes' => 1, 'checksum' => str_repeat('a', 64),
        'visibility' => 'private', 'status' => 'ready', 'scan_status' => 'not_scanned',
        'created_at' => now(), 'updated_at' => now(),
    ];

    foreach ([['visibility', 'secret'], ['status', 'levitating'], ['scan_status', 'probably_fine'], ['type', 'hologram']] as [$column, $bogus]) {
        $rejected = false;

        try {
            DB::transaction(fn () => DB::table('media_files')->insert(
                array_merge($base, ['id' => (string) Str::ulid(), $column => $bogus])
            ));
        } catch (QueryException) {
            $rejected = true;
        }

        expect($rejected)->toBeTrue("media_files.{$column} should have rejected [{$bogus}]");
    }
});
