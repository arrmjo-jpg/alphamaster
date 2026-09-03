<?php

declare(strict_types=1);

namespace App\Modules\Media\Contracts;

use Illuminate\Http\UploadedFile;

/**
 * Where bytes live, without the caller knowing where that is.
 *
 * Implemented over Laravel's filesystem disks rather than replacing them: disks are
 * already a driver abstraction, and a second configuration system for the same thing
 * would be the duplication brief section 20 warns against. What this adds is a
 * boundary business code cannot see past.
 */
interface MediaStorageContract
{
    /**
     * Persist the file and return the storage key it was written to.
     */
    public function put(UploadedFile $file, string $directory, string $disk): string;

    /**
     * Whether the object still exists.
     */
    public function exists(string $path, string $disk): bool;

    /**
     * Remove the object. Returns false when it was already gone, which a retrying
     * purge job must treat as success rather than as failure.
     */
    public function delete(string $path, string $disk): bool;

    /**
     * A publicly reachable URL, when the disk can produce one.
     */
    public function url(string $path, string $disk): ?string;

    /**
     * A signed URL that expires, for media that requires authorization.
     */
    public function temporaryUrl(string $path, string $disk, int $ttlSeconds): ?string;

    /**
     * The disk new uploads are written to by default.
     */
    public function defaultDisk(): string;
}
