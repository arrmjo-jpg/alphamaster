<?php

declare(strict_types=1);

namespace App\Modules\Notification\Models;

use App\Modules\Core\Models\BaseModel;
use App\Modules\Notification\Enums\NotificationChannel;
use App\Modules\Notification\Enums\NotificationType;
use App\Modules\User\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One recipient's decision about one notification on one channel.
 *
 * Absence of a row means the default applies, so a user who has never expressed a
 * preference is not represented by a wall of rows asserting the obvious.
 *
 * @property string $id
 * @property string $user_id
 * @property NotificationType $type
 * @property NotificationChannel $channel
 * @property bool $enabled
 */
class NotificationPreference extends BaseModel
{
    protected $table = 'notification_preferences';

    protected $fillable = [
        'user_id',
        'type',
        'channel',
        'enabled',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return array_merge(parent::casts(), [
            'type' => NotificationType::class,
            'channel' => NotificationChannel::class,
            'enabled' => 'boolean',
        ]);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
