<?php

declare(strict_types=1);

namespace App\Modules\Settings\Models;

use App\Modules\Core\Models\BaseModel;
use App\Modules\Settings\Enums\SettingType;
use App\Modules\Settings\Exceptions\SettingDecryptionException;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use InvalidArgumentException;

/**
 * @property string $id
 * @property string $group
 * @property string $key
 * @property string|null $value
 * @property SettingType $type
 * @property bool $is_secret
 * @property bool $is_public
 * @property string|null $description
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 *
 * @method static Builder|Setting public ()
 * @method static Builder|Setting group(string $group)
 */
class Setting extends BaseModel
{
    /**
     * Placeholder returned instead of a secret value, and accepted on write to mean
     * "leave the stored secret untouched".
     */
    public const SECRET_MASK = '••••••••';

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'settings';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'group',
        'key',
        'value',
        'type',
        'is_secret',
        'is_public',
        'description',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return array_merge(parent::casts(), [
            'type' => SettingType::class,
            'is_secret' => 'boolean',
            'is_public' => 'boolean',
        ]);
    }

    /**
     * Bootstrap the model and its traits.
     *
     * This guard is a fast-fail convenience only. The authoritative enforcement of the
     * "a secret is never public" invariant lives in the database (CHECK constraint on
     * PostgreSQL/MySQL, trigger on SQLite), so it still holds when model events are
     * disabled or when rows are written through the query builder.
     */
    protected static function booted(): void
    {
        static::saving(function (Setting $setting): void {
            if ($setting->is_secret && $setting->is_public) {
                throw new InvalidArgumentException("Setting [{$setting->group}.{$setting->key}] cannot be both secret and public.");
            }
        });
    }

    /**
     * Scope public settings.
     */
    public function scopePublic(Builder $query): Builder
    {
        return $query->where('is_public', true);
    }

    /**
     * Scope by group.
     */
    public function scopeGroup(Builder $query, string $group): Builder
    {
        return $query->where('group', $group);
    }

    /**
     * Get the raw string value, decrypting it first when the setting is secret.
     *
     * A null return means the setting is explicitly unset (SQL NULL) — it never means
     * "decryption failed", which raises SettingDecryptionException instead.
     *
     * @throws SettingDecryptionException
     */
    public function getRawValue(): ?string
    {
        if ($this->value === null) {
            return null;
        }

        if (! $this->is_secret) {
            return $this->value;
        }

        try {
            return Crypt::decryptString($this->value);
        } catch (DecryptException $e) {
            throw new SettingDecryptionException($this->group, $this->key, $e);
        }
    }

    /**
     * Set the raw value, encrypting it when the setting is secret.
     *
     * Passing null stores SQL NULL, i.e. explicitly unsets the value.
     */
    public function setRawValue(?string $raw): void
    {
        if ($raw === null) {
            $this->value = null;

            return;
        }

        $this->value = $this->is_secret ? Crypt::encryptString($raw) : $raw;
    }

    /**
     * Cast the stored value to its strict typed PHP representation.
     *
     * @throws SettingDecryptionException
     */
    public function getTypedValue(): mixed
    {
        $raw = $this->getRawValue();

        if ($raw === null) {
            return null;
        }

        return self::castValue($raw, $this->type);
    }

    /**
     * Strict and deterministic conversion from the stored string to a PHP value.
     */
    public static function castValue(string $raw, SettingType $type): mixed
    {
        return match ($type) {
            SettingType::BOOLEAN => self::strictCastBoolean($raw),
            SettingType::INTEGER => self::strictCastInteger($raw),
            SettingType::FLOAT => self::strictCastFloat($raw),
            SettingType::JSON => self::strictCastJson($raw),
            SettingType::STRING => $raw,
        };
    }

    /**
     * Convert an incoming PHP value into its canonical stored string.
     *
     * Null semantics are explicit: null in means null out, i.e. "unset this setting"
     * (SQL NULL). It is never coerced into an empty string, and no other type is ever
     * coerced implicitly — every unsupported input raises InvalidArgumentException.
     */
    public static function serializeValue(mixed $val, SettingType $type): ?string
    {
        if ($val === null) {
            return null;
        }

        return match ($type) {
            SettingType::BOOLEAN => self::strictCastBoolean($val) ? 'true' : 'false',
            SettingType::INTEGER => (string) self::strictCastInteger($val),
            SettingType::FLOAT => self::encodeFloat(self::strictCastFloat($val)),
            SettingType::JSON => self::encodeJson(self::strictCastJson($val)),
            SettingType::STRING => self::strictCastString($val),
        };
    }

    /**
     * Strict string casting: accepts strings and numbers, rejects everything else.
     *
     * Notably rejects arrays and objects, which PHP would otherwise coerce to the
     * literal "Array" (a warning, not an error) and silently corrupt the setting.
     */
    public static function strictCastString(mixed $val): string
    {
        if (is_string($val)) {
            return $val;
        }

        if (is_int($val)) {
            return (string) $val;
        }

        if (is_float($val)) {
            return self::encodeFloat($val);
        }

        throw new InvalidArgumentException(
            'Invalid value of type ['.get_debug_type($val).'] for string setting. Expected a string or a number.'
        );
    }

    /**
     * Strict boolean casting: accepts true/false, 1/0, "true"/"false", "1"/"0".
     */
    public static function strictCastBoolean(mixed $val): bool
    {
        if (is_bool($val)) {
            return $val;
        }

        if (is_int($val)) {
            if ($val === 1) {
                return true;
            }
            if ($val === 0) {
                return false;
            }
            throw new InvalidArgumentException("Invalid integer [{$val}] for boolean setting.");
        }

        if (is_string($val)) {
            $lower = strtolower(trim($val));
            if ($lower === 'true' || $lower === '1') {
                return true;
            }
            if ($lower === 'false' || $lower === '0') {
                return false;
            }
            throw new InvalidArgumentException("Invalid string [{$val}] for boolean setting. Expected 'true', 'false', '1', or '0'.");
        }

        throw new InvalidArgumentException(
            'Invalid value of type ['.get_debug_type($val).'] for boolean setting.'
        );
    }

    /**
     * Strict integer casting.
     */
    public static function strictCastInteger(mixed $val): int
    {
        if (is_int($val)) {
            return $val;
        }

        if (is_string($val) && preg_match('/^-?\d+$/', trim($val)) === 1) {
            return (int) trim($val);
        }

        throw new InvalidArgumentException(
            'Invalid value ['.self::describe($val).'] for integer setting.'
        );
    }

    /**
     * Strict float casting.
     */
    public static function strictCastFloat(mixed $val): float
    {
        if (is_int($val)) {
            return (float) $val;
        }

        if (is_float($val)) {
            if (! is_finite($val)) {
                throw new InvalidArgumentException('Invalid non-finite value for float setting.');
            }

            return $val;
        }

        if (is_string($val) && is_numeric(trim($val))) {
            $parsed = (float) trim($val);

            if (! is_finite($parsed)) {
                throw new InvalidArgumentException('Invalid non-finite value for float setting.');
            }

            return $parsed;
        }

        throw new InvalidArgumentException(
            'Invalid value ['.self::describe($val).'] for float setting.'
        );
    }

    /**
     * Strict JSON casting: accepts an array or a valid JSON document string.
     */
    public static function strictCastJson(mixed $val): mixed
    {
        if (is_array($val)) {
            return $val;
        }

        if (is_string($val)) {
            try {
                return json_decode($val, true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException $e) {
                throw new InvalidArgumentException('Invalid JSON payload for json setting: '.$e->getMessage(), 0, $e);
            }
        }

        throw new InvalidArgumentException(
            'Invalid value of type ['.get_debug_type($val).'] for json setting. Expected an array or a JSON string.'
        );
    }

    /**
     * Encode a decoded JSON value back into its canonical stored form.
     */
    private static function encodeJson(mixed $decoded): string
    {
        try {
            return json_encode($decoded, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } catch (\JsonException $e) {
            throw new InvalidArgumentException('Unable to encode value for json setting: '.$e->getMessage(), 0, $e);
        }
    }

    /**
     * Encode a float locale-independently so it round-trips through storage.
     */
    private static function encodeFloat(float $val): string
    {
        return var_export($val, true);
    }

    /**
     * Render a value for an error message without triggering array-to-string warnings.
     */
    private static function describe(mixed $val): string
    {
        return match (true) {
            is_string($val) => $val,
            is_scalar($val) => var_export($val, true),
            default => get_debug_type($val),
        };
    }
}
