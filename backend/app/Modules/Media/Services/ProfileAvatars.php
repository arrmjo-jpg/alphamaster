<?php

declare(strict_types=1);

namespace App\Modules\Media\Services;

use App\Modules\Core\Contracts\ProfileAvatarContract;
use App\Modules\Media\Contracts\MediaServiceContract;
use App\Modules\Media\Data\MediaUpload;
use App\Modules\Media\Enums\MediaVisibility;
use App\Modules\Media\Exceptions\MediaValidationException;
use App\Modules\Media\Models\MediaFile;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;

/**
 * An account's profile picture, kept as ordinary media (ADR 0051 §4).
 *
 * A picture is a public image in the `avatar` collection, attached to the account. One is
 * current at a time: setting a new one retires the previous ones through the ordinary
 * delete, so the bytes are purged on the media schedule rather than removed on the spot.
 */
class ProfileAvatars implements ProfileAvatarContract
{
    public const COLLECTION = 'avatar';

    public function __construct(private readonly MediaServiceContract $media) {}

    public function current(Model $account): ?MediaFile
    {
        return MediaFile::query()
            ->where('attachable_type', $account->getMorphClass())
            ->where('attachable_id', $account->getKey())
            ->where('collection', self::COLLECTION)
            ->latest('created_at')
            ->latest('id')
            ->first();
    }

    public function urlFor(object $account): ?string
    {
        if (! $account instanceof Model) {
            return null;
        }

        $media = $this->current($account);

        return $media === null ? null : $this->media->urlFor($media, $account);
    }

    /**
     * Store a new picture for the account and retire the ones before it.
     *
     * @throws MediaValidationException
     */
    public function replace(Model $account, UploadedFile $file): MediaFile
    {
        $previous = MediaFile::query()
            ->where('attachable_type', $account->getMorphClass())
            ->where('attachable_id', $account->getKey())
            ->where('collection', self::COLLECTION)
            ->get();

        // Stored first: a rejected file leaves the current picture exactly as it was.
        $media = $this->media->store(new MediaUpload(
            file: $file,
            visibility: MediaVisibility::PUBLIC,
            collection: self::COLLECTION,
            attachable: $account,
            uploadedBy: (string) $account->getKey(),
        ));

        foreach ($previous as $old) {
            $this->media->delete($old);
        }

        return $media;
    }

    /**
     * Retire the account's picture. Answers whether there was one.
     */
    public function remove(Model $account): bool
    {
        $pictures = MediaFile::query()
            ->where('attachable_type', $account->getMorphClass())
            ->where('attachable_id', $account->getKey())
            ->where('collection', self::COLLECTION)
            ->get();

        foreach ($pictures as $picture) {
            $this->media->delete($picture);
        }

        return $pictures->isNotEmpty();
    }
}
