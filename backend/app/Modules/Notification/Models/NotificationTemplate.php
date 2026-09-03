<?php

declare(strict_types=1);

namespace App\Modules\Notification\Models;

use App\Modules\Core\Concerns\HasTranslations;
use App\Modules\Core\Models\BaseModel;
use App\Modules\Notification\Enums\NotificationType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * The subject and body a notification renders from, per locale.
 *
 * @property string $id
 * @property NotificationType $type
 * @property bool $is_active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 *
 * @method static Builder|NotificationTemplate active()
 */
class NotificationTemplate extends BaseModel
{
    use HasTranslations;

    protected $table = 'notification_templates';

    protected $fillable = [
        'type',
        'is_active',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return array_merge(parent::casts(), [
            'type' => NotificationType::class,
            'is_active' => 'boolean',
        ]);
    }

    public function translationModel(): string
    {
        return NotificationTemplateTranslation::class;
    }

    /**
     * @return array<int, string>
     */
    public function translatableAttributes(): array
    {
        return ['subject', 'body'];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
