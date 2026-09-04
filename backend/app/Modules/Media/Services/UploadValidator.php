<?php

declare(strict_types=1);

namespace App\Modules\Media\Services;

use App\Modules\Media\Exceptions\MediaValidationException;
use Illuminate\Http\UploadedFile;

/**
 * Refuses a file before a single byte is written to storage.
 *
 * Everything here is decided from the bytes themselves. The client-supplied MIME type
 * and the filename are treated as claims to be checked, never as facts: both are
 * fully attacker controlled.
 */
class UploadValidator
{
    /**
     * Content types the platform accepts, by detected type.
     *
     * An allow list rather than a deny list. A deny list is a promise to have thought
     * of every dangerous format, which nobody can keep.
     *
     * @var array<string, array<int, string>>
     */
    private const ALLOWED = [
        'image' => ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/avif'],
        'video' => ['video/mp4', 'video/webm', 'video/quicktime'],
        'audio' => ['audio/mpeg', 'audio/ogg', 'audio/wav', 'audio/mp4'],
        'document' => ['application/pdf', 'text/plain', 'text/csv'],
    ];

    /**
     * Extensions refused outright, whatever the detected type says.
     *
     * Belt and braces alongside the allow list: a file that somehow presents an
     * acceptable content type must still not land with a name the web server or an
     * operator's shell might act on.
     *
     * @var array<int, string>
     */
    private const FORBIDDEN_EXTENSIONS = [
        'php', 'phar', 'phtml', 'php3', 'php4', 'php5', 'php7', 'php8',
        'exe', 'dll', 'so', 'dylib', 'bat', 'cmd', 'com', 'scr', 'msi',
        'sh', 'bash', 'zsh', 'ps1', 'psm1', 'vbs', 'js', 'jar', 'py', 'rb', 'pl',
        'htaccess', 'htpasswd',
    ];

    private const MAX_BYTES = 104857600; // 100 MB

    private const MAX_FILENAME_LENGTH = 255;

    /**
     * Validate and return the detected content type.
     *
     * @throws MediaValidationException
     */
    public function validate(UploadedFile $file): string
    {
        if (! $file->isValid()) {
            throw new MediaValidationException('upload_failed', 'api.error.media.upload_failed');
        }

        $size = $file->getSize();

        if ($size === false || $size <= 0) {
            throw new MediaValidationException('empty_file', 'api.error.media.empty_file');
        }

        if ($size > self::MAX_BYTES) {
            throw new MediaValidationException(
                'too_large',
                'api.error.media.too_large',
                ['bytes' => self::MAX_BYTES]
            );
        }

        $name = (string) $file->getClientOriginalName();
        $this->assertFilenameIsSafe($name);

        $extension = mb_strtolower((string) $file->getClientOriginalExtension());
        $this->assertExtensionIsPermitted($extension);

        // Detected from content, not from the request. getMimeType() reads the file.
        $detected = (string) $file->getMimeType();
        $this->assertContentTypeIsAllowed($detected);
        $this->assertExtensionAgreesWithContent($extension, $detected);

        return $detected;
    }

    /**
     * A filename must not be usable to escape the directory it is written into, and
     * must not smuggle control characters into a path or a log line.
     *
     * @throws MediaValidationException
     */
    private function assertFilenameIsSafe(string $name): void
    {
        if ($name === '' || mb_strlen($name) > self::MAX_FILENAME_LENGTH) {
            throw new MediaValidationException('bad_filename', 'api.error.media.filename_invalid');
        }

        if (str_contains($name, '..')
            || str_contains($name, '/')
            || str_contains($name, '\\')
            || str_contains($name, "\0")
            || preg_match('/[\x00-\x1F\x7F]/', $name) === 1
        ) {
            throw new MediaValidationException('bad_filename', 'api.error.media.filename_forbidden_characters');
        }
    }

    /**
     * @throws MediaValidationException
     */
    private function assertExtensionIsPermitted(string $extension): void
    {
        if (in_array($extension, self::FORBIDDEN_EXTENSIONS, true)) {
            throw new MediaValidationException('forbidden_extension', 'api.error.media.forbidden_extension');
        }
    }

    /**
     * @throws MediaValidationException
     */
    private function assertContentTypeIsAllowed(string $detected): void
    {
        foreach (self::ALLOWED as $allowed) {
            if (in_array($detected, $allowed, true)) {
                return;
            }
        }

        throw new MediaValidationException('unsupported_type', 'api.error.media.unsupported_type');
    }

    /**
     * Every accepted extension, and the content types it may legitimately carry.
     *
     * Checking the family alone would be nearly useless: an image extension on an
     * image content type always agrees regardless of which image it is, and a .txt
     * holding a JPEG would pass. Mapping each extension explicitly means the name and
     * the bytes have to describe the same format, not merely the same neighbourhood.
     *
     * @var array<string, array<int, string>>
     */
    private const EXTENSION_CONTENT_TYPES = [
        'jpg' => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'png' => ['image/png'],
        'gif' => ['image/gif'],
        'webp' => ['image/webp'],
        'avif' => ['image/avif'],
        'mp4' => ['video/mp4'],
        'm4v' => ['video/mp4'],
        'webm' => ['video/webm'],
        'mov' => ['video/quicktime'],
        'mp3' => ['audio/mpeg'],
        'ogg' => ['audio/ogg'],
        'wav' => ['audio/wav'],
        'm4a' => ['audio/mp4'],
        'pdf' => ['application/pdf'],
        'txt' => ['text/plain'],
        'csv' => ['text/csv', 'text/plain'],
    ];

    /**
     * The extension must describe the same format as the bytes.
     *
     * This is what stops a script reaching storage as `photo.jpg`. The name claims one
     * format, the content is another, and the disagreement is itself the signal —
     * which is why it is checked rather than either side being trusted alone.
     *
     * @throws MediaValidationException
     */
    private function assertExtensionAgreesWithContent(string $extension, string $detected): void
    {
        if ($extension === '') {
            throw new MediaValidationException('bad_filename', 'api.error.media.extension_missing');
        }

        $permitted = self::EXTENSION_CONTENT_TYPES[$extension] ?? null;

        if ($permitted === null || ! in_array($detected, $permitted, true)) {
            throw new MediaValidationException(
                'extension_mismatch',
                'api.error.media.extension_mismatch'
            );
        }
    }
}
