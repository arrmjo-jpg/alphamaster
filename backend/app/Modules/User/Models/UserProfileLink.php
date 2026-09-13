<?php

declare(strict_types=1);

namespace App\Modules\User\Models;

use App\Modules\Core\Models\BaseModel;
use App\Modules\User\Enums\ProfileLinkPlatform;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A link an account shows to its presence elsewhere (ADR 0051 §4).
 *
 * Not a social identity: it proves nothing and signs nobody in.
 *
 * @property string $id
 * @property string $user_id
 * @property ProfileLinkPlatform $platform
 * @property string $url
 * @property int $position
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class UserProfileLink extends BaseModel
{
    protected $table = 'user_profile_links';

    protected $fillable = [
        'user_id',
        'platform',
        'url',
        'position',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return array_merge(parent::casts(), [
            'platform' => ProfileLinkPlatform::class,
            'position' => 'integer',
        ]);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
