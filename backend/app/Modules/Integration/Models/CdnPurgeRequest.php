<?php

declare(strict_types=1);

namespace App\Modules\Integration\Models;

use App\Modules\Core\Delivery\EdgeInvalidationKind;
use App\Modules\Core\Models\BaseModel;
use App\Modules\Integration\Enums\CdnPurgeStatus;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One vendor call's worth of edge invalidation, and its outcome (ADR 0053 §4).
 *
 * @property string $id
 * @property string|null $integration_provider_id
 * @property string $driver
 * @property EdgeInvalidationKind $kind
 * @property list<string> $items
 * @property int $item_count
 * @property CdnPurgeStatus $status
 * @property int $attempts
 * @property Carbon|null $available_at
 * @property string|null $reason
 * @property string|null $requested_by
 * @property string|null $error_code
 * @property string|null $error_message
 * @property string|null $provider_reference
 * @property Carbon|null $completed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read IntegrationProvider|null $provider
 */
class CdnPurgeRequest extends BaseModel
{
    protected $table = 'cdn_purge_requests';

    protected $fillable = [
        'integration_provider_id',
        'driver',
        'kind',
        'items',
        'item_count',
        'status',
        'attempts',
        'available_at',
        'reason',
        'requested_by',
        'error_code',
        'error_message',
        'provider_reference',
        'completed_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return array_merge(parent::casts(), [
            'kind' => EdgeInvalidationKind::class,
            'status' => CdnPurgeStatus::class,
            'items' => 'array',
            'item_count' => 'integer',
            'attempts' => 'integer',
            'available_at' => 'datetime',
            'completed_at' => 'datetime',
        ]);
    }

    /**
     * @return BelongsTo<IntegrationProvider, $this>
     */
    public function provider(): BelongsTo
    {
        return $this->belongsTo(IntegrationProvider::class, 'integration_provider_id');
    }
}
