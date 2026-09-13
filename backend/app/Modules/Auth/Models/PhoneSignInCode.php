<?php

declare(strict_types=1);

namespace App\Modules\Auth\Models;

use App\Modules\Core\Models\BaseModel;
use Illuminate\Support\Carbon;

/**
 * An outstanding sign-in or registration code for a phone number (ADR 0051 §1).
 *
 * @property string $id
 * @property string $phone_hash
 * @property string $otp_hash
 * @property Carbon $expires_at
 * @property Carbon $sent_at
 * @property int $attempts
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class PhoneSignInCode extends BaseModel
{
    protected $table = 'phone_sign_in_codes';

    protected $fillable = [
        'phone_hash',
        'otp_hash',
        'expires_at',
        'sent_at',
        'attempts',
    ];

    /**
     * Neither the lookup digest nor the code hash has any business in a response.
     *
     * @var list<string>
     */
    protected $hidden = ['phone_hash', 'otp_hash'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return array_merge(parent::casts(), [
            'expires_at' => 'datetime',
            'sent_at' => 'datetime',
            'attempts' => 'integer',
        ]);
    }

    public function isLive(): bool
    {
        return $this->expires_at->isFuture();
    }
}
