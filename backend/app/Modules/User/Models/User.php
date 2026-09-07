<?php

declare(strict_types=1);

namespace App\Modules\User\Models;

use App\Modules\Core\Contracts\AdminIdentity;
use App\Modules\User\Enums\AccountType;
use App\Modules\User\Exceptions\InvalidPhoneNumberException;
use App\Modules\User\Support\PhoneNumber;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

/**
 * @property string $id
 * @property string $name
 * @property string $email
 * @property string|null $phone
 * @property string|null $phone_hash
 * @property Carbon|null $email_verified_at
 * @property string|null $preferred_locale
 * @property string $password
 * @property AccountType $account_type
 * @property bool $is_active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 *
 * @method static Builder|User active()
 * @method static Builder|User admins()
 */
class User extends Authenticatable implements AdminIdentity
{
    /** @use HasFactory<UserFactory> */
    /**
     * HasRoles is attached here because Spatie resolves roles and permissions
     * through the authenticatable model and cannot work otherwise. Attaching it does
     * NOT make every account an RBAC account: admin roles and permissions are only
     * ever granted, revoked or evaluated through AdminRbac, which refuses any account
     * whose type is not admin. The trait is plumbing; the boundary is that service.
     */
    use HasApiTokens, HasFactory, HasRoles, HasUlids, Notifiable;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'users';

    /**
     * The "type" of the primary key ID.
     *
     * @var string
     */
    protected $keyType = 'string';

    /**
     * Indicates if the IDs are auto-incrementing.
     *
     * @var bool
     */
    public $incrementing = false;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        // `phone` and not `phone_hash`. The hash is derived from the number by the
        // mutator below and is never accepted from outside, so the two cannot be set
        // to values that disagree.
        'phone',
        'password',
        'preferred_locale',
        'is_active',
    ];

    /**
     * Attributes that may never be mass assigned.
     *
     * account_type decides whether an account can hold admin:access and participate
     * in admin RBAC at all. It is changed only through the explicit promotion and
     * demotion workflow, never by anything that forwards request input into a model.
     *
     * @var array<int, string>
     */
    protected $guarded = [
        'id',
        'account_type',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        // A lookup value, not a presentable one. Nothing outside the login query has
        // a use for it, and publishing a keyed digest of a phone number invites
        // exactly the offline matching the key exists to prevent.
        'phone_hash',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'id' => 'string',
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'account_type' => AccountType::class,
            'is_active' => 'boolean',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    /**
     * The number and its lookup hash, written as one operation.
     *
     * Set mutators are the only place this can live and still hold. A service that
     * computed both and assigned them separately would work, right up until the next
     * caller — a seeder, a console command, a factory, a test — set `phone` on its own
     * and left a hash describing the previous number. The unique constraint would then
     * be guarding a value the row no longer has.
     *
     * Canonicalisation happens here too, so `$user->phone` is the canonical form
     * immediately after assignment rather than after a save and a refresh.
     *
     * @return Attribute<string|null, array{phone: string|null, phone_hash: string|null}>
     */
    protected function phone(): Attribute
    {
        return Attribute::make(
            set: function (?string $value): array {
                $canonical = PhoneNumber::canonicalise($value);

                return [
                    'phone' => $canonical,
                    'phone_hash' => $canonical === null
                        ? null
                        : PhoneNumber::lookupHash($canonical),
                ];
            },
        );
    }

    /**
     * Find an account by phone number, or return null.
     *
     * The query is on the hash, never on `phone`: the hash is the indexed column and
     * the one the unique constraint covers, and matching the canonical text instead
     * would be an unindexed scan that also disagrees with the constraint about what
     * counts as the same number.
     *
     * A value that is not a readable phone number is not an error here — it is simply
     * not a match. This is reached from a login path where the caller does not yet
     * know which kind of identifier it holds, and raising would tell an unauthenticated
     * caller that its input was well-formed, which is a distinction that endpoint is
     * careful not to make.
     */
    public static function findByPhone(?string $value): ?self
    {
        try {
            $canonical = PhoneNumber::canonicalise($value);
        } catch (InvalidPhoneNumberException) {
            return null;
        }

        if ($canonical === null) {
            return null;
        }

        return static::query()
            ->where('phone_hash', PhoneNumber::lookupHash($canonical))
            ->first();
    }

    /**
     * Scope accounts that are not suspended.
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope administrator accounts.
     */
    public function scopeAdmins(Builder $query): Builder
    {
        return $query->where('account_type', AccountType::ADMIN->value);
    }

    /**
     * Whether this account is an administrator.
     *
     * The single place the rest of the platform should ask. Nothing infers
     * administrative standing from a role, a permission, or a group membership.
     */
    public function isAdmin(): bool
    {
        return $this->account_type === AccountType::ADMIN;
    }

    /**
     * Create a new factory instance for the model.
     */
    protected static function newFactory(): UserFactory
    {
        return UserFactory::new();
    }
}
