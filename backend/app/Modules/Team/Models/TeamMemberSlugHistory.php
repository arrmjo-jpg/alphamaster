<?php

declare(strict_types=1);

namespace App\Modules\Team\Models;

use App\Modules\Core\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A slug an active member's profile used to have in one language, kept so the old address
 * redirects (ADR 0055 §6).
 *
 * @property string $id
 * @property string $team_member_id
 * @property string $locale
 * @property string $slug
 */
class TeamMemberSlugHistory extends BaseModel
{
    public const UPDATED_AT = null;

    protected $table = 'team_member_slug_history';

    /**
     * @var list<string>
     */
    protected $fillable = ['team_member_id', 'locale', 'slug'];

    /**
     * @return BelongsTo<TeamMember, $this>
     */
    public function member(): BelongsTo
    {
        return $this->belongsTo(TeamMember::class, 'team_member_id');
    }
}
