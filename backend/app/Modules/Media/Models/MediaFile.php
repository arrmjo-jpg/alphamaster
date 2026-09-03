<?php

declare(strict_types=1);

namespace App\Modules\Media\Models;

use App\Modules\Core\Models\BaseModel;
use App\Modules\Media\Enums\MediaStatus;
use App\Modules\Media\Enums\MediaType;
use App\Modules\Media\Enums\MediaVisibility;
use App\Modules\Media\Enums\ScanStatus;
use App\Modules\User\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * A stored file and everything the platform knows about it.
 *
 * This is the only media type business modules ever see (ADR 0024). Spatie's own
 * model is not exposed, and neither is the disk or the storage path: both are hidden
 * from serialisation so an API response cannot leak where bytes actually live.
 *
 * @property string $id
 * @property string|null $attachable_type
 * @property string|null $attachable_id
 * @property string $collection
 * @property string $disk
 * @property string $path
 * @property string $original_filename
 * @property string $mime_type
 * @property string|null $extension
 * @property MediaType $type
 * @property int $size_bytes
 * @property string $checksum
 * @property MediaVisibility $visibility
 * @property MediaStatus $status
 * @property ScanStatus $scan_status
 * @property string|null $failure_reason
 * @property int|null $width
 * @property int|null $height
 * @property int|null $duration_seconds
 * @property array<string, mixed>|null $metadata
 * @property string|null $uploaded_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 *
 * @method static Builder|MediaFile ready()
 * @method static Builder|MediaFile inCollection(string $collection)
 */
class MediaFile extends BaseModel
{
    use SoftDeletes;

    protected $table = 'media_files';

    /**
     * disk and path are absent: where bytes live is decided by the storage layer, not
     * by anything that forwards input into a model.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'collection',
        'original_filename',
        'visibility',
    ];

    /**
     * Never serialised. A client has no use for the disk or the storage key, and
     * exposing them turns every API response into a map of the storage layout.
     *
     * @var array<int, string>
     */
    protected $hidden = ['disk', 'path'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return array_merge(parent::casts(), [
            'type' => MediaType::class,
            'visibility' => MediaVisibility::class,
            'status' => MediaStatus::class,
            'scan_status' => ScanStatus::class,
            'metadata' => 'array',
            'size_bytes' => 'integer',
            'width' => 'integer',
            'height' => 'integer',
            'duration_seconds' => 'integer',
            'deleted_at' => 'datetime',
        ]);
    }

    /**
     * What this file belongs to, if anything yet.
     */
    public function attachable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Who uploaded it. Null once that account is gone; the file record remains.
     */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function scopeReady(Builder $query): Builder
    {
        return $query->where('status', MediaStatus::READY->value);
    }

    public function scopeInCollection(Builder $query, string $collection): Builder
    {
        return $query->where('collection', $collection);
    }

    /**
     * Whether this file may be served at all.
     *
     * Readiness and scanning are separate conditions: a file that processed correctly
     * but scanned as infected must not be served, so both are checked rather than
     * assuming the lifecycle status subsumes the scan result.
     */
    public function isServable(): bool
    {
        return $this->status->isServable() && $this->scan_status->permitsServing();
    }

    public function isPubliclyReadable(): bool
    {
        return $this->visibility === MediaVisibility::PUBLIC;
    }

    /**
     * Record a terminal failure, keeping the reason for an operator to read.
     */
    public function markFailed(MediaStatus $status, string $reason): void
    {
        $this->forceFill([
            'status' => $status,
            'failure_reason' => $reason,
        ])->save();
    }
}
