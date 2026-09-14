<?php

declare(strict_types=1);

namespace App\Modules\Media\Models;

use App\Modules\Core\Models\BaseModel;
use App\Modules\Media\Enums\MediaAnalysisReviewDecision;
use App\Modules\User\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A person's reading of an analysis, recorded beside it (ADR 0054).
 *
 * @property string $id
 * @property string $media_analysis_id
 * @property string|null $reviewer_id
 * @property MediaAnalysisReviewDecision $decision
 * @property string|null $note
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class MediaAnalysisReview extends BaseModel
{
    protected $table = 'media_analysis_reviews';

    protected $fillable = [
        'media_analysis_id',
        'reviewer_id',
        'decision',
        'note',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return array_merge(parent::casts(), [
            'decision' => MediaAnalysisReviewDecision::class,
        ]);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewer_id');
    }
}
