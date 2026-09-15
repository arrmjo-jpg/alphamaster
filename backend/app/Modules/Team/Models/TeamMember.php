<?php

declare(strict_types=1);

namespace App\Modules\Team\Models;

use App\Modules\Core\Concerns\HasTranslations;
use App\Modules\Core\Models\BaseModel;
use App\Modules\Core\Seo\HasSeoMeta;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A member of the team: one record, whatever languages their profile is written in
 * (ADR 0055 §2).
 *
 * @property string $id
 * @property bool $is_active
 * @property int $sort_order
 * @property string|null $avatar_media_id
 * @property array<string, string>|null $social_links
 * @property string|null $created_by
 * @property string|null $updated_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class TeamMember extends BaseModel
{
    use HasSeoMeta;

    /** @use HasTranslations<TeamMemberTranslation> */
    use HasTranslations;

    protected $table = 'team_members';

    /**
     * @var list<string>
     */
    protected $fillable = ['is_active', 'sort_order', 'avatar_media_id', 'social_links', 'created_by', 'updated_by'];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'is_active' => false,
        'sort_order' => 0,
    ];

    protected function casts(): array
    {
        return array_merge(parent::casts(), [
            'is_active' => 'boolean',
            'sort_order' => 'integer',
            'social_links' => 'array',
        ]);
    }

    public function translationModel(): string
    {
        return TeamMemberTranslation::class;
    }

    public function translationForeignKey(): string
    {
        return 'team_member_id';
    }

    public function translatableAttributes(): array
    {
        return ['name', 'position', 'bio', 'slug'];
    }

    /**
     * @return HasMany<TeamMemberSlugHistory, $this>
     */
    public function slugHistory(): HasMany
    {
        return $this->hasMany(TeamMemberSlugHistory::class, 'team_member_id');
    }
}
