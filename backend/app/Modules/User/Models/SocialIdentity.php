<?php

declare(strict_types=1);

namespace App\Modules\User\Models;

use App\Modules\Core\Models\BaseModel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * An identity a social provider can sign in to an account with (ADR 0050).
 *
 * `provider` and `provider_subject` are the identity; the email is a snapshot for display
 * and is never looked up. The owner and the identity never change once the row exists —
 * the database refuses it — and unlinking sets `unlinked_at` rather than deleting, so a
 * subject stays reserved to the account it belonged to.
 *
 * Nothing here is a credential. No provider token is stored, and none can be.
 *
 * @property string $id
 * @property string $user_id
 * @property string $provider
 * @property string $provider_subject
 * @property string|null $email
 * @property Carbon $linked_at
 * @property Carbon|null $last_used_at
 * @property Carbon|null $unlinked_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 *
 * @method static Builder|SocialIdentity linked()
 */
class SocialIdentity extends BaseModel
{
    protected $table = 'social_identities';

    protected $fillable = [
        'user_id',
        'provider',
        'provider_subject',
        'email',
        'linked_at',
        'last_used_at',
        'unlinked_at',
    ];

    /**
     * The subject identifies a person at a vendor, so it is not serialised by accident.
     *
     * @var list<string>
     */
    protected $hidden = ['provider_subject'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return array_merge(parent::casts(), [
            'linked_at' => 'datetime',
            'last_used_at' => 'datetime',
            'unlinked_at' => 'datetime',
        ]);
    }

    /**
     * Identities that can sign in today.
     */
    public function scopeLinked(Builder $query): Builder
    {
        return $query->whereNull('unlinked_at');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isLinked(): bool
    {
        return $this->unlinked_at === null;
    }
}
