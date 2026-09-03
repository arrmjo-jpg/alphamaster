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
 * @property string $secret
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
    ];

    /**
     * The secret is never serialised, in any representation.
     *
     * @var array<int, string>
     */
    protected $hidden = ['secret'];

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
        try {
            return Crypt::decryptString($this->secret);
        } catch (DecryptException $e) {
            throw new MfaSecretDecryptionException($this->user_id, $this->type->value, $e);
        }
    }
}
