<?php

declare(strict_types=1);

namespace App\Modules\Media\Services\Storage;

use App\Modules\Media\Contracts\MediaStorageContract;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use League\Flysystem\UnableToGenerateTemporaryUrl;
use RuntimeException;

/**
 * Storage over Laravel's filesystem disks.
 *
 * Laravel already abstracts local, S3 and everything else behind one interface, so
 * this adds a boundary rather than a second abstraction: business code sees
 * MediaStorageContract, never a disk name, and the disk each file lives on is
 * recorded per row so a later migration between backends can go file by file.
 */
class DiskMediaStorage implements MediaStorageContract
{
    /**
     * Write the file under a generated name.
     *
     * The stored name is generated rather than derived from the client's filename:
     * the original is kept as metadata for display, but nothing attacker controlled
     * ever becomes part of a path.
     */
    public function put(UploadedFile $file, string $directory, string $disk): string
    {
        $extension = mb_strtolower((string) $file->getClientOriginalExtension());
        $name = (string) Str::ulid().($extension !== '' ? '.'.$extension : '');
        $path = trim($directory, '/').'/'.$name;

        $stream = fopen($file->getRealPath(), 'rb');

        if ($stream === false) {
            throw new RuntimeException('The uploaded file could not be read.');
        }

        try {
            Storage::disk($disk)->put($path, $stream);
        } finally {
            fclose($stream);
        }

        return $path;
    }

    public function exists(string $path, string $disk): bool
    {
        return Storage::disk($disk)->exists($path);
    }

    /**
     * Already absent counts as deleted, so a retried purge job converges rather than
     * failing on its second attempt.
     */
    public function delete(string $path, string $disk): bool
    {
        if (! Storage::disk($disk)->exists($path)) {
            return false;
        }

        return Storage::disk($disk)->delete($path);
    }

    public function url(string $path, string $disk): ?string
    {
        try {
            return Storage::disk($disk)->url($path);
        } catch (RuntimeException) {
            // A local, non-public disk cannot produce one; the caller falls back to a
            // streamed response rather than treating this as an error.
            return null;
        }
    }

    public function temporaryUrl(string $path, string $disk, int $ttlSeconds): ?string
    {
        try {
            return Storage::disk($disk)->temporaryUrl($path, now()->addSeconds($ttlSeconds));
        } catch (UnableToGenerateTemporaryUrl|RuntimeException) {
            // Local disks cannot sign URLs. Private media is then served through the
            // application route instead, which is slower but correct.
            return null;
        }
    }

    public function defaultDisk(): string
    {
        return (string) config('filesystems.default', 'local');
    }
}
