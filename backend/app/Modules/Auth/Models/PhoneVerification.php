<?php

declare(strict_types=1);

namespace App\Modules\Auth\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * The outstanding code that would confirm an account's phone number.
 *
 * One row per account at most, replaced by each send and deleted the moment a number
 * is confirmed. Nothing here is readable as a credential: the code is stored as a hash
 * and the number it went to as the same keyed digest `users` uses for lookup.
 *
 * @property string $id
 * @property string $user_id
 * @property string $otp_hash
 * @property string $destination_hash
 * @property Carbon $expires_at
 * @property Carbon $sent_at
 * @property int $attempts
 */
class PhoneVerification extends Model
{
    use HasUlids;

    protected $fillable = [
        'user_id',
        'otp_hash',
        'destination_hash',
        'expires_at',
        'sent_at',
        'attempts',
    ];

    /**
     * Hidden rather than merely unused: nothing outside this module has a reason to
     * read the digest of somebody's code, and a resource that serialised the model
     * wholesale should not be the way that changes.
     *
     * @var list<string>
     */
    protected $hidden = ['otp_hash', 'destination_hash'];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'sent_at' => 'datetime',
            'attempts' => 'integer',
        ];
    }

    /**
     * Whether this code is still worth comparing against.
     */
    public function isLive(): bool
    {
        return $this->expires_at->isFuture();
    }
}
