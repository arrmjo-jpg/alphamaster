<?php

declare(strict_types=1);

namespace App\Modules\Settings\Services;

use App\Modules\Settings\Contracts\SettingServiceInterface;
use App\Modules\Settings\Exceptions\SettingGroupNotFoundException;
use App\Modules\Settings\Exceptions\UnknownSettingKeyException;
use App\Modules\Settings\Models\Setting;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class SettingService implements SettingServiceInterface
{
    public const CACHE_PREFIX = 'settings:';

    public const CACHE_TTL = 86400; // 24 hours

    /**
     * Cache key holding the list of groups that expose at least one public setting.
     *
     * Consulted before any per-group cache write so that an unknown (attacker supplied)
     * group name can never mint a cache entry of its own.
     */
    public const PUBLIC_GROUPS_KEY = self::CACHE_PREFIX.'public:groups';

    /**
     * Get a typed setting value by key formatted as 'group.key'.
     *
     * Returns null — not $default — for a provisioned setting whose stored value is
     * NULL; $default is reserved for a key that does not exist at all.
     */
    public function get(string $key, mixed $default = null): mixed
    {
        [$group, $settingKey] = $this->splitKey($key);

        $index = $this->getGroupIndex($group);

        if (array_key_exists($settingKey, $index['values'])) {
            return $index['values'][$settingKey];
        }

        // Secret values are deliberately absent from the cache and read straight from
        // the database on demand, so decrypted plaintext never reaches the cache store.
        if (in_array($settingKey, $index['secrets'], true)) {
            return $this->readSecret($group, $settingKey);
        }

        return $default;
    }

    /**
     * Set / update the value of an already provisioned setting.
     *
     * Settings are provisioned by migrations and seeders; this never creates one, and
     * the stored type is authoritative, so callers do not get to declare a type.
     */
    public function set(string $group, string $key, mixed $value): void
    {
        $this->updateGroup($group, [$key => $value]);
    }

    /**
     * Batch update an array of settings within a group atomically.
     *
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>
     */
    public function updateGroup(string $group, array $settings): array
    {
        return DB::transaction(function () use ($group, $settings): array {
            $existing = Setting::query()->where('group', $group)->get()->keyBy('key');

            if ($existing->isEmpty()) {
                throw new SettingGroupNotFoundException($group);
            }

            $updatedValues = [];

            foreach ($settings as $key => $val) {
                $key = (string) $key;

                /** @var Setting|null $setting */
                $setting = $existing->get($key);

                if ($setting === null) {
                    throw new UnknownSettingKeyException($group, $key);
                }

                // A submitted mask means "keep the stored secret as it is".
                if ($setting->is_secret && $val === Setting::SECRET_MASK) {
                    $updatedValues[$key] = $setting->value === null ? null : Setting::SECRET_MASK;

                    continue;
                }

                // serializeValue maps null to null (explicitly unset) and rejects every
                // value it cannot represent exactly, rather than coercing it.
                $serialized = Setting::serializeValue($val, $setting->type);
                $setting->setRawValue($serialized);
                $setting->save();

                $updatedValues[$key] = match (true) {
                    $serialized === null => null,
                    $setting->is_secret => Setting::SECRET_MASK,
                    default => Setting::castValue($serialized, $setting->type),
                };
            }

            // Invalidate only once the transaction has actually committed. Clearing
            // inside the transaction lets a concurrent reader repopulate the cache from
            // pre-commit state and pin stale values for a full TTL.
            DB::afterCommit(function () use ($group): void {
                $this->clearCache($group);
            });

            return $updatedValues;
        });
    }

    /**
     * Retrieve all public settings grouped by group name for public API.
     * Minimal payload containing only group => [key => typedValue].
     *
     * @return array<string, array<string, mixed>>
     */
    public function getPublicSettings(): array
    {
        return Cache::remember(self::CACHE_PREFIX.'public', self::CACHE_TTL, function (): array {
            $result = [];

            foreach ($this->publicQuery()->get() as $record) {
                $result[$record->group][$record->key] = $record->getTypedValue();
            }

            return $result;
        });
    }

    /**
     * Retrieve public settings for a specific group.
     *
     * @return array<string, mixed>
     *
     * @throws SettingGroupNotFoundException when the group exposes no public settings
     */
    public function getPublicGroup(string $group): array
    {
        if (! in_array($group, $this->getPublicGroupNames(), true)) {
            throw new SettingGroupNotFoundException($group);
        }

        return Cache::remember(self::CACHE_PREFIX.'group:'.$group.':public', self::CACHE_TTL, function () use ($group): array {
            $result = [];

            foreach ($this->publicQuery()->where('group', $group)->get() as $record) {
                $result[$record->key] = $record->getTypedValue();
            }

            return $result;
        });
    }

    /**
     * Retrieve all settings in a group for admin inspection (with secrets masked).
     *
     * @return array<int, array<string, mixed>>
     *
     * @throws SettingGroupNotFoundException
     */
    public function getAdminGroup(string $group): array
    {
        $settings = Setting::query()
            ->where('group', $group)
            ->orderBy('key')
            ->get();

        if ($settings->isEmpty()) {
            throw new SettingGroupNotFoundException($group);
        }

        return $settings->map(fn (Setting $s): array => $this->formatAdminSetting($s))->all();
    }

    /**
     * Retrieve all settings across all groups for admin inspection (with secrets masked).
     *
     * @return array<string, array<int, array<string, mixed>>>
     */
    public function getAdminAll(): array
    {
        $grouped = [];

        foreach (Setting::query()->orderBy('group')->orderBy('key')->get() as $setting) {
            $grouped[$setting->group][] = $this->formatAdminSetting($setting);
        }

        return $grouped;
    }

    /**
     * Invalidate cached settings.
     */
    public function clearCache(?string $group = null): void
    {
        Cache::forget(self::CACHE_PREFIX.'public');
        Cache::forget(self::PUBLIC_GROUPS_KEY);

        $groups = $group !== null
            ? [$group]
            : Setting::query()->distinct()->pluck('group')->all();

        foreach ($groups as $name) {
            Cache::forget(self::CACHE_PREFIX.'group:'.$name.':public');
            Cache::forget(self::CACHE_PREFIX.'internal:group:'.$name);
        }
    }

    /**
     * Split a 'group.key' reference into its two parts.
     *
     * @return array{0: string, 1: string}
     */
    protected function splitKey(string $key): array
    {
        $parts = explode('.', $key, 2);

        if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
            throw new InvalidArgumentException("Setting key must be in the format 'group.key', received [{$key}].");
        }

        return [$parts[0], $parts[1]];
    }

    /**
     * Base query for settings safe to expose publicly.
     */
    protected function publicQuery(): Builder
    {
        return Setting::query()
            ->where('is_public', true)
            ->where('is_secret', false);
    }

    /**
     * The groups exposing at least one public setting.
     *
     * @return array<int, string>
     */
    protected function getPublicGroupNames(): array
    {
        return Cache::remember(self::PUBLIC_GROUPS_KEY, self::CACHE_TTL, function (): array {
            return $this->publicQuery()->distinct()->orderBy('group')->pluck('group')->all();
        });
    }

    /**
     * Cached index of a group.
     *
     * Only non-secret values are cached. Secrets contribute their key name alone, so
     * the cache store never holds decrypted secret material.
     *
     * @return array{values: array<string, mixed>, secrets: array<int, string>}
     */
    protected function getGroupIndex(string $group): array
    {
        $cacheKey = self::CACHE_PREFIX.'internal:group:'.$group;
        $cached = Cache::get($cacheKey);

        // A cache entry written by an older revision can have a different shape. Treat
        // anything that does not match the current contract as a miss and rebuild it,
        // rather than letting a stale entry fault every read until the cache is purged.
        if (is_array($cached) && is_array($cached['values'] ?? null) && is_array($cached['secrets'] ?? null)) {
            return $cached;
        }

        $values = [];
        $secrets = [];

        foreach (Setting::query()->where('group', $group)->get() as $record) {
            if ($record->is_secret) {
                $secrets[] = $record->key;

                continue;
            }

            $values[$record->key] = $record->getTypedValue();
        }

        $index = ['values' => $values, 'secrets' => $secrets];

        Cache::put($cacheKey, $index, self::CACHE_TTL);

        return $index;
    }

    /**
     * Read and decrypt a single secret directly from the database, bypassing the cache.
     */
    protected function readSecret(string $group, string $key): mixed
    {
        $setting = Setting::query()
            ->where('group', $group)
            ->where('key', $key)
            ->first();

        return $setting?->getTypedValue();
    }

    /**
     * Format a setting record for Admin API presentation.
     *
     * @return array<string, mixed>
     */
    protected function formatAdminSetting(Setting $setting): array
    {
        return [
            'id' => $setting->id,
            'group' => $setting->group,
            'key' => $setting->key,
            // Secret plaintext is never rendered; a masked secret is distinguishable
            // from an unset one, which reads as null.
            'value' => $setting->is_secret
                ? ($setting->value === null ? null : Setting::SECRET_MASK)
                : $setting->getTypedValue(),
            'type' => $setting->type->value,
            'is_secret' => $setting->is_secret,
            'is_public' => $setting->is_public,
            'description' => $setting->description,
            'updated_at' => $setting->updated_at?->toIso8601String(),
        ];
    }
}
