<?php

declare(strict_types=1);

namespace App\Modules\User\Models;

use App\Modules\Core\Contracts\AdminIdentity;
use App\Modules\User\Enums\AccountType;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Builder;
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
