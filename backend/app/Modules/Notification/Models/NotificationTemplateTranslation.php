<?php

declare(strict_types=1);

namespace App\Modules\Notification\Models;

use App\Modules\Core\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One locale's rendering of a template.
 *
 * @property string $id
 * @property string $notification_template_id
 * @property string $locale
 * @property string $subject
 * @property string $body
 */
class NotificationTemplateTranslation extends BaseModel
{
    protected $table = 'notification_template_translations';

    protected $fillable = [
        'notification_template_id',
        'locale',
        'subject',
        'body',
    ];

    public function template(): BelongsTo
    {
        return $this->belongsTo(NotificationTemplate::class, 'notification_template_id');
    }
}
