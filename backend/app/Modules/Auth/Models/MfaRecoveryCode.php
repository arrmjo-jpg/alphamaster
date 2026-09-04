<?php

declare(strict_types=1);

namespace App\Modules\Auth\Models;

use App\Modules\Core\Models\BaseModel;
use App\Modules\User\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $user_id
 * @property string $code_hash
 * @property Carbon|null $used_at
 *
 * @method static Builder|MfaRecoveryCode unused()
 */
class MfaRecoveryCode extends BaseModel
{
    protected $table = 'mfa_recovery_codes';

    protected $fillable = [
        'user_id',
        'code_hash',
        'used_at',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = ['code_hash'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return array_merge(parent::casts(), [
            'used_at' => 'datetime',
        ]);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeUnused(Builder $query): Builder
    {
        return $query->whereNull('used_at');
    }
}
