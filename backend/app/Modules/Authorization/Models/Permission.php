<?php

declare(strict_types=1);

namespace App\Modules\Authorization\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission as SpatiePermission;

/**
 * An administrative permission.
 *
 * Extends Spatie's model to carry the owning module (ADR 0014), so each module can
 * seed and query its own permissions without reaching into a global list.
 *
 * @property string $module
 *
 * @method static Builder|Permission forModule(string $module)
 */
class Permission extends SpatiePermission
{
    protected $fillable = [
        'name',
        'guard_name',
        'module',
    ];

    public function scopeForModule(Builder $query, string $module): Builder
    {
        return $query->where('module', $module);
    }

    /**
     * The human label for this permission, in the request's locale.
     *
     * The permission catalogue is fixed by a deployment — a new permission is a
     * code change — so its labels live in the language files keyed by the
     * identifier, as ADR 0030 requires for code-defined sets. The key is flat and
     * contains the identifier's own dots: `permission.users.update`.
     *
     * A permission with no translation is humanised from its identifier rather
     * than rendered blank. ADR 0030 asks for visibly wrong over empty, and an
     * administrator choosing permissions needs something readable even when a key
     * has been missed.
     */
    public function displayLabel(): string
    {
        $key = 'permission.'.$this->name;
        $translated = __($key);

        if (is_string($translated) && $translated !== $key) {
            return $translated;
        }

        return self::humanize((string) $this->name);
    }

    /**
     * `users.update` becomes `Update users`, which is what the label would have
     * said had someone written it.
     */
    public static function humanize(string $name): string
    {
        $segments = explode('.', $name);
        $action = array_pop($segments);
        $subject = implode(' ', $segments);

        return trim(Str::ucfirst(str_replace('_', ' ', (string) $action)).' '.str_replace('_', ' ', $subject));
    }
}
