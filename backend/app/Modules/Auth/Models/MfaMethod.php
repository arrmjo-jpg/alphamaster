<?php

declare(strict_types=1);

namespace App\Modules\Auth\Models;

use App\Modules\Auth\Enums\MfaType;
use App\Modules\Auth\Exceptions\MfaSecretDecryptionException;
use App\Modules\Core\Models\BaseModel;
use App\Modules\User\Models\User;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;

/**
 * @property string $id
 * @property string $user_id
 * @property MfaType $type
 * @property string|null $secret
 * @property string|null $destination
 * @property string|null $otp_hash
 * @property Carbon|null $otp_expires_at
 * @property Carbon|null $otp_sent_at
 * @property Carbon|null $confirmed_at
 * @property int|null $last_used_slice
 * @property Carbon|null $last_used_at
 *
 * @method static Builder|MfaMethod confirmed()
 */
class MfaMethod extends BaseModel
{
    protected $table = 'mfa_methods';

    protected $fillable = [
        'user_id',
        'type',
        'secret',
        'confirmed_at',
        'last_used_slice',
        'last_used_at',
        'otp_expires_at',
        'otp_sent_at',
    ];

    /**
     * The secret is never serialised, in any representation.
     *
     * @var list<string>
     */
    protected $hidden = ['secret', 'destination', 'otp_hash'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return array_merge(parent::casts(), [
            'type' => MfaType::class,
            'confirmed_at' => 'datetime',
            'last_used_slice' => 'integer',
            'last_used_at' => 'datetime',
            'otp_expires_at' => 'datetime',
            'otp_sent_at' => 'datetime',
        ]);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeConfirmed(Builder $query): Builder
    {
        return $query->whereNotNull('confirmed_at');
    }

    public function isConfirmed(): bool
    {
        return $this->confirmed_at !== null;
    }

    /**
     * Store the shared secret, encrypted at rest.
     */
    public function setSecret(string $plaintext): void
    {
        $this->secret = Crypt::encryptString($plaintext);
    }

    /**
     * Read the shared secret.
     *
     * Fails loudly rather than returning ciphertext, which would silently make
     * every subsequent code comparison fail closed for an unexplained reason.
     *
     * @throws MfaSecretDecryptionException
     */
    public function getSecret(): string
    {
        if ($this->secret === null) {
            throw new MfaSecretDecryptionException($this->user_id, $this->type->value);
        }

        try {
            return Crypt::decryptString($this->secret);
        } catch (DecryptException $e) {
            throw new MfaSecretDecryptionException($this->user_id, $this->type->value, $e);
        }
    }

    /**
     * Store where delivered codes go, encrypted at rest.
     *
     * A phone number is personal data and belongs under the same protection as a
     * shared secret, not in a plaintext column.
     */
    public function setDestination(string $plaintext): void
    {
        $this->destination = Crypt::encryptString($plaintext);
    }

    /**
     * Read the delivery destination.
     *
     * @throws MfaSecretDecryptionException
     */
    public function getDestination(): string
    {
        if ($this->destination === null) {
            throw new MfaSecretDecryptionException($this->user_id, $this->type->value);
        }

        try {
            return Crypt::decryptString($this->destination);
        } catch (DecryptException $e) {
            throw new MfaSecretDecryptionException($this->user_id, $this->type->value, $e);
        }
    }

    /**
     * A destination safe to show a client: enough to recognise, not enough to reuse.
     */
    public function maskedDestination(): string
    {
        $value = $this->getDestination();
        $length = mb_strlen($value);

        return $length <= 4
            ? str_repeat('*', $length)
            : str_repeat('*', $length - 4).mb_substr($value, -4);
    }

    /**
     * Whether a delivered code is currently outstanding.
     */
    public function hasPendingOtp(): bool
    {
        return $this->otp_hash !== null
            && $this->otp_expires_at !== null
            && $this->otp_expires_at->isFuture();
    }

    /**
     * Discard any outstanding code, so it cannot be presented again.
     */
    public function clearOtp(): void
    {
        $this->forceFill([
            'otp_hash' => null,
            'otp_expires_at' => null,
        ])->save();
    }
}
