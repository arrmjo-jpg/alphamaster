<?php

declare(strict_types=1);

namespace App\Modules\Team\Models;

use App\Modules\Core\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One language's profile for a team member.
 *
 * @property string $id
 * @property string $team_member_id
 * @property string $locale
 * @property string|null $name
 * @property string|null $position
 * @property string|null $bio
 * @property string|null $slug
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class TeamMemberTranslation extends BaseModel
{
    protected $table = 'team_member_translations';

    /**
     * @var list<string>
     */
    protected $fillable = ['team_member_id', 'locale', 'name', 'position', 'bio', 'slug'];

    /**
     * @return BelongsTo<TeamMember, $this>
     */
    public function member(): BelongsTo
    {
        return $this->belongsTo(TeamMember::class, 'team_member_id');
    }
}
