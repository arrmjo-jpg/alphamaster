<?php

declare(strict_types=1);

namespace App\Modules\Settings\Models;

use App\Modules\Core\Models\BaseModel;
use App\Modules\Settings\Contracts\SettingServiceInterface;
use App\Modules\Settings\Enums\SettingType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Crypt;
use InvalidArgumentException;

class Setting extends BaseModel
{
    public const SECRET_MASK = '••••••••';

    protected $table = 'settings';

    protected $fillable = [
        'group',
        'key',
        'value',
        'type',
        'is_secret',
        'is_public',
        'description',
    ];

    protected function casts(): array
    {
        return [
            'type' => SettingType::class,
            'is_secret' => 'boolean',
            'is_public' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (Setting $setting): void {
            // Invariant: Secret settings are NEVER public
            if ($setting->is_secret && $setting->is_public) {
                throw new InvalidArgumentException("Setting [{$setting->group}.{$setting->key}] cannot be both secret and public.");
            }
        });

        static::saved(function (Setting $setting): void {
            if (app()->bound(SettingServiceInterface::class)) {
                app(SettingServiceInterface::class)->clearCache($setting->group);
            }
        });

        static::deleted(function (Setting $setting): void {
            if (app()->bound(SettingServiceInterface::class)) {
                app(SettingServiceInterface::class)->clearCache($setting->group);
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
     * Get the decrypted raw string value if secret, or plaintext value.
     */
    public function getRawValue(): ?string
    {
        if ($this->value === null) {
            return null;
        }

        if ($this->is_secret) {
            try {
                return Crypt::decryptString($this->value);
            } catch (\Throwable) {
                return $this->value;
            }
        }

        return $this->value;
    }

    /**
     * Set the raw value, encrypting if is_secret is true.
     */
    public function setRawValue(?string $raw): void
    {
        if ($raw === null) {
            $this->value = null;

            return;
        }

        if ($this->is_secret) {
            $this->value = Crypt::encryptString($raw);
        } else {
            $this->value = $raw;
        }
    }

    /**
     * Cast the raw value to its strict typed PHP representation.
     */
    public function getTypedValue(): mixed
    {
        $raw = $this->getRawValue();

        if ($raw === null) {
            return null;
        }

        $type = $this->type instanceof SettingType ? $this->type : SettingType::from((string) $this->type);

        return self::castValue($raw, $type);
    }

    /**
     * Strict and deterministic conversion from string representation to PHP type.
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

        throw new InvalidArgumentException('Invalid type for boolean setting.');
    }

    /**
     * Strict integer casting.
     */
    public static function strictCastInteger(mixed $val): int
    {
        if (is_int($val)) {
            return $val;
        }

        if (is_string($val) && preg_match('/^-?\d+$/', trim($val))) {
            return (int) trim($val);
        }

        throw new InvalidArgumentException("Invalid value [{$val}] for integer setting.");
    }

    /**
     * Strict float casting.
     */
    public static function strictCastFloat(mixed $val): float
    {
        if (is_float($val) || is_int($val)) {
            return (float) $val;
        }

        if (is_string($val) && is_numeric(trim($val))) {
            return (float) trim($val);
        }

        throw new InvalidArgumentException("Invalid value [{$val}] for float setting.");
    }

    /**
     * Strict JSON casting.
     */
    public static function strictCastJson(mixed $val): mixed
    {
        if (is_array($val)) {
            return $val;
        }

        if (is_string($val)) {
            $decoded = json_decode($val, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                return $decoded;
            }
            throw new InvalidArgumentException('Invalid JSON payload for json setting: '.json_last_error_msg());
        }

        throw new InvalidArgumentException('Invalid value for json setting.');
    }

    /**
     * Convert any PHP typed value to string for storage.
     */
    public static function serializeValue(mixed $val, SettingType $type): string
    {
        return match ($type) {
            SettingType::BOOLEAN => self::strictCastBoolean($val) ? 'true' : 'false',
            SettingType::INTEGER => (string) self::strictCastInteger($val),
            SettingType::FLOAT => (string) self::strictCastFloat($val),
            SettingType::JSON => is_string($val) ? (string) json_encode(self::strictCastJson($val)) : (string) json_encode($val),
            SettingType::STRING => (string) $val,
        };
    }
}
