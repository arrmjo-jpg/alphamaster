<?php

declare(strict_types=1);

namespace App\Modules\Notification\Models;

use App\Modules\Core\Models\BaseModel;
use App\Modules\Notification\Enums\DevicePlatform;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * One handset a push can reach.
 *
 * @property string $id
 * @property string $user_id
 * @property string $token
 * @property string $device_id
 * @property DevicePlatform $platform
 * @property string|null $label
 * @property string|null $access_token_id
 * @property Carbon|null $last_seen_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 *
 * @method static Builder|PushDevice forAccount(string $userId)
 */
class PushDevice extends BaseModel
{
    protected $table = 'push_devices';

    protected $fillable = [
        'user_id',
        'token',
        'device_id',
        'platform',
        'label',
        'access_token_id',
        'last_seen_at',
    ];

    /**
     * The registration token is a delivery address for a specific handset, and
     * publishing it would let anybody holding the response send to that device
     * directly. An operator needs to know a device exists, not how to reach it.
     *
     * @var list<string>
     */
    protected $hidden = ['token'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return array_merge(parent::casts(), [
            'platform' => DevicePlatform::class,
            'last_seen_at' => 'datetime',
        ]);
    }

    public function scopeForAccount(Builder $query, string $userId): Builder
    {
        return $query->where('user_id', $userId);
    }

    /**
     * Enough of the token to recognise a row, and not enough to send to it.
     *
     * The last few characters only. A device registry an operator is reading needs to
     * distinguish two rows for the same handset; it does not need the address.
     */
    public function tokenHint(): string
    {
        return mb_substr($this->token, -6);
    }
}
