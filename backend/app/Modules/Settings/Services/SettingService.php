<?php

declare(strict_types=1);

namespace App\Modules\Settings\Services;

use App\Modules\Settings\Contracts\SettingServiceInterface;
use App\Modules\Settings\Enums\SettingType;
use App\Modules\Settings\Models\Setting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class SettingService implements SettingServiceInterface
{
    public const CACHE_PREFIX = 'settings:';

    public const CACHE_TTL = 86400; // 24 hours

    /**
     * Get a typed setting value by key formatted as 'group.key'.
     */
    public function get(string $key, mixed $default = null): mixed
    {
        $parts = explode('.', $key, 2);
        if (count($parts) !== 2) {
            throw new InvalidArgumentException("Setting key must be in the format 'group.key', received [{$key}].");
        }

        [$group, $settingKey] = $parts;

        // Try to fetch from internal group cache
        $internalGroup = $this->getInternalGroupCached($group);

        if (array_key_exists($settingKey, $internalGroup)) {
            return $internalGroup[$settingKey];
        }

        return $default;
    }

    /**
     * Set / update a setting value.
     */
    public function set(string $group, string $key, mixed $value, ?string $type = null): void
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
            $updatedValues = [];

            foreach ($settings as $key => $val) {
                if (! $existing->has($key)) {
                    throw new InvalidArgumentException("Setting [{$group}.{$key}] does not exist. Cannot update unknown setting.");
                }

                /** @var Setting $setting */
                $setting = $existing->get($key);
                $type = $setting->type instanceof SettingType ? $setting->type : SettingType::from((string) $setting->type);

                // If is_secret and value is the mask '••••••••', keep current value intact
                if ($setting->is_secret && $val === Setting::SECRET_MASK) {
                    $updatedValues[$key] = Setting::SECRET_MASK;

                    continue;
                }

                // Strictly serialize and validate type
                $serialized = Setting::serializeValue($val, $type);
                $setting->setRawValue($serialized);
                $setting->save();

                $updatedValues[$key] = $setting->is_secret ? Setting::SECRET_MASK : Setting::castValue($serialized, $type);
            }

            $this->clearCache($group);

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
            $publicRecords = Setting::query()
                ->where('is_public', true)
                ->where('is_secret', false)
                ->get(['group', 'key', 'value', 'type']);

            $result = [];
            foreach ($publicRecords as $record) {
                $type = $record->type instanceof SettingType ? $record->type : SettingType::from((string) $record->type);
                $typedValue = $record->value !== null ? Setting::castValue($record->value, $type) : null;
                $result[$record->group][$record->key] = $typedValue;
            }

            return $result;
        });
    }

    /**
     * Retrieve public settings for a specific group.
     *
     * @return array<string, mixed>
     */
    public function getPublicGroup(string $group): array
    {
        return Cache::remember(self::CACHE_PREFIX.'group:'.$group.':public', self::CACHE_TTL, function () use ($group): array {
            $publicRecords = Setting::query()
                ->where('group', $group)
                ->where('is_public', true)
                ->where('is_secret', false)
                ->get(['key', 'value', 'type']);

            $result = [];
            foreach ($publicRecords as $record) {
                $type = $record->type instanceof SettingType ? $record->type : SettingType::from((string) $record->type);
                $result[$record->key] = $record->value !== null ? Setting::castValue($record->value, $type) : null;
            }

            return $result;
        });
    }

    /**
     * Internal server-side cache for a group. Secrets are NOT exposed to public caches.
     *
     * @return array<string, mixed>
     */
    protected function getInternalGroupCached(string $group): array
    {
        return Cache::remember(self::CACHE_PREFIX.'internal:group:'.$group, self::CACHE_TTL, function () use ($group): array {
            $records = Setting::query()->where('group', $group)->get();
            $result = [];

            foreach ($records as $record) {
                $result[$record->key] = $record->getTypedValue();
            }

            return $result;
        });
    }

    /**
     * Retrieve all settings in a group for admin inspection (with secrets masked).
     *
     * @return array<int, array<string, mixed>>
     */
    public function getAdminGroup(string $group): array
    {
        return Setting::query()
            ->where('group', $group)
            ->orderBy('key')
            ->get()
            ->map(fn (Setting $s): array => $this->formatAdminSetting($s))
            ->all();
    }

    /**
     * Retrieve all settings across all groups for admin inspection (with secrets masked).
     *
     * @return array<string, array<int, array<string, mixed>>>
     */
    public function getAdminAll(): array
    {
        $all = Setting::query()->orderBy('group')->orderBy('key')->get();
        $grouped = [];

        foreach ($all as $setting) {
            $grouped[$setting->group][] = $this->formatAdminSetting($setting);
        }

        return $grouped;
    }

    /**
     * Format a setting record for Admin API presentation.
     *
     * @return array<string, mixed>
     */
    protected function formatAdminSetting(Setting $setting): array
    {
        $type = $setting->type instanceof SettingType ? $setting->type->value : (string) $setting->type;

        return [
            'id' => $setting->id,
            'group' => $setting->group,
            'key' => $setting->key,
            'value' => $setting->is_secret ? Setting::SECRET_MASK : $setting->getTypedValue(),
            'type' => $type,
            'is_secret' => (bool) $setting->is_secret,
            'is_public' => (bool) $setting->is_public,
            'description' => $setting->description,
            'updated_at' => $setting->updated_at?->toIso8601String(),
        ];
    }

    /**
     * Invalidate cached settings in Redis.
     */
    public function clearCache(?string $group = null): void
    {
        Cache::forget(self::CACHE_PREFIX.'public');

        if ($group !== null) {
            Cache::forget(self::CACHE_PREFIX.'group:'.$group.':public');
            Cache::forget(self::CACHE_PREFIX.'internal:group:'.$group);
        } else {
            // Clear all possible groups from DB
            try {
                $groups = Setting::query()->distinct()->pluck('group')->all();
                foreach ($groups as $grp) {
                    Cache::forget(self::CACHE_PREFIX.'group:'.$grp.':public');
                    Cache::forget(self::CACHE_PREFIX.'internal:group:'.$grp);
                }
            } catch (\Throwable) {
                // Ignore DB error on drop/migration
            }
        }
    }
}
