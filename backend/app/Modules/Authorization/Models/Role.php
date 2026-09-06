<?php

declare(strict_types=1);

namespace App\Modules\Authorization\Models;

use App\Modules\Core\Concerns\HasTranslations;
use Illuminate\Support\Str;
use RuntimeException;
use Spatie\Permission\Models\Role as SpatieRole;

/**
 * An administrative role.
 *
 * Roles exist solely to group admin permissions. Holding one is never what makes an
 * account an administrator — that is decided by account_type alone — so a role
 * relation on a regular user grants nothing.
 */
class Role extends SpatieRole
{
    /** @use HasTranslations<RoleTranslation> */
    use HasTranslations;

    protected $fillable = [
        'name',
        'guard_name',
    ];

    /**
     * The identifier is generated once and never changes.
     *
     * Enforced here rather than only in the request, so no path — a service, a
     * console command, a future controller — can rename a role that permissions
     * and assignments already reference by name. Renaming is what the label is
     * for.
     */
    protected static function booted(): void
    {
        static::updating(function (self $role): void {
            if ($role->isDirty('name')) {
                throw new RuntimeException(sprintf(
                    'A role identifier is immutable; [%s] cannot become [%s]. Change its label instead.',
                    (string) $role->getOriginal('name'),
                    (string) $role->name
                ));
            }
        });
    }

    public function translationModel(): string
    {
        return RoleTranslation::class;
    }

    /**
     * @return array<int, string>
     */
    public function translatableAttributes(): array
    {
        return ['label'];
    }

    /**
     * The human label for this role, in the request's locale.
     *
     * Three sources, in order. A role an administrator created carries its label
     * relationally, because it did not exist when the code shipped (ADR 0030).
     * A built-in role is defined by a deployment, so its label is a catalogue key
     * like any other code-defined set. Anything else is humanised from the
     * identifier rather than rendered blank.
     */
    public function displayLabel(): string
    {
        $translated = $this->translate('label');

        if (is_string($translated) && $translated !== '') {
            return $translated;
        }

        $key = 'role.'.$this->name;
        $catalogued = __($key);

        if (is_string($catalogued) && $catalogued !== $key) {
            return $catalogued;
        }

        return Str::headline((string) $this->name);
    }
}
