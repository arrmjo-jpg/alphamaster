<?php

declare(strict_types=1);

namespace App\Modules\Integration\Models;

use App\Modules\Core\Models\BaseModel;
use App\Modules\Integration\Enums\IntegrationCapability;
use App\Modules\Integration\Enums\UsageStatus;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One attempt against one provider.
 *
 * Capability and driver are denormalised so a line stays readable after the provider
 * row it referred to has been removed.
 *
 * @property string $id
 * @property string|null $integration_provider_id
 * @property IntegrationCapability $capability
 * @property string $driver
 * @property UsageStatus $status
 * @property string|null $reference
 * @property string|null $error_code
 * @property string|null $error_message
 * @property int|null $duration_ms
 * @property int|null $units
 * @property Carbon|null $created_at
 */
class IntegrationUsageLog extends BaseModel
{
    protected $table = 'integration_usage_logs';

    protected $fillable = [
        'integration_provider_id',
        'capability',
        'driver',
        'status',
        'reference',
        'error_code',
        'error_message',
        'duration_ms',
        // Tokens, for a capability that charges by them. Null where an attempt is the
        // unit and the count is always one.
        'units',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return array_merge(parent::casts(), [
            'capability' => IntegrationCapability::class,
            'status' => UsageStatus::class,
            'duration_ms' => 'integer',
            'units' => 'integer',
        ]);
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(IntegrationProvider::class, 'integration_provider_id');
    }
}
