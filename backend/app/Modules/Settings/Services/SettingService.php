<?php

declare(strict_types=1);

namespace App\Modules\Settings\Services;

use App\Modules\Core\Audit\AuditAction;
use App\Modules\Core\Cache\CacheNamespace;
use App\Modules\Core\Contracts\AuditRecorderContract;
use App\Modules\Core\Contracts\LocaleResolverInterface;
use App\Modules\Core\Contracts\PlatformCacheContract;
use App\Modules\Settings\Contracts\SettingServiceInterface;
use App\Modules\Settings\Exceptions\SettingGroupNotFoundException;
use App\Modules\Settings\Exceptions\UnknownSettingKeyException;
use App\Modules\Settings\Models\Setting;
use App\Modules\Settings\Models\SettingRevision;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class SettingService implements SettingServiceInterface
{
    /**
     * The resources this service caches, named rather than spelled out at each call
     * site. The platform cache composes the key and owns the TTL (ADR 0035).
     */
    public const RESOURCE_PUBLIC = 'public';

    public const RESOURCE_PUBLIC_GROUP = 'public_group';

    public const RESOURCE_PUBLIC_GROUPS = 'public_groups';

    public const RESOURCE_GROUP_INDEX = 'group_index';

    public function __construct(
        private readonly PlatformCacheContract $cache,
        private readonly AuditRecorderContract $audit,
    ) {}

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
            // Translations come with them: a localized write reads the value it is
            // superseding for the caller's locale (ADR 0040), and lazy loading is
            // disabled platform-wide — which caught this rather than letting it become
            // a query per setting.
            $existing = Setting::query()
                ->where('group', $group)
                ->with('translations')
                ->get()
                ->keyBy('key');

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

                // Whether the setting held anything before, which is all the audit
                // trail is allowed to know about a secret's previous state.
                $previousValue = $setting->value;

                // serializeValue maps null to null (explicitly unset) and rejects every
                // value it cannot represent exactly, rather than coercing it.
                $serialized = Setting::serializeValue($val, $setting->type);

                // Captured before the version advances, so the revision carries the
                // version its value actually belonged to (ADR 0040).
                $this->recordRevision($setting, $serialized);

                // The counter advances on every write, localized or not. A timestamp
                // would not: `timestampsTz` stores whole seconds, so two saves a moment
                // apart would look identical (ADR 0038).
                $setting->version = $setting->version + 1;

                if ($setting->is_localized) {
                    // A localized write lands in the caller's locale and leaves every
                    // other language alone. Writing it to the base column instead would
                    // silently change what every other locale falls back to.
                    $setting->setLocalizedValue($this->locale(), $serialized);
                    $setting->save();
                } else {
                    $setting->setRawValue($serialized);
                    $setting->save();
                }

                $updatedValues[$key] = match (true) {
                    $serialized === null => null,
                    $setting->is_secret => Setting::SECRET_MASK,
                    default => Setting::castValue($serialized, $setting->type),
                };

                $this->recordChange($setting, $serialized, $previousValue);
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
        $locale = $this->locale();

        return $this->cache->remember(CacheNamespace::SETTINGS, self::RESOURCE_PUBLIC, ['locale' => $locale], function () use ($locale): array {
            $result = [];

            foreach ($this->publicQuery()->with('translations')->get() as $record) {
                $result[$record->group][$record->key] = $record->getTypedValue($locale);
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

        $locale = $this->locale();

        return $this->cache->remember(CacheNamespace::SETTINGS, self::RESOURCE_PUBLIC_GROUP, ['group' => $group, 'locale' => $locale], function () use ($group, $locale): array {
            $result = [];

            foreach ($this->publicQuery()->where('group', $group)->with('translations')->get() as $record) {
                $result[$record->key] = $record->getTypedValue($locale);
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
     * What the settings in a group used to be, newest first (ADR 0040).
     *
     * Secrets contribute nothing, because they have no revisions — an interface
     * reading this sees the non-secret history and nothing that hints at what a
     * credential was.
     *
     * @return array<int, array<string, mixed>>
     *
     * @throws SettingGroupNotFoundException
     */
    public function groupHistory(string $group, ?string $key = null, int $limit = 100): array
    {
        $settings = Setting::query()
            ->where('group', $group)
            ->when($key !== null, fn ($query) => $query->where('key', $key))
            ->get();

        if ($settings->isEmpty()) {
            // An unknown group and an unknown key answer the same way the rest of the
            // admin API does, rather than returning an empty list that reads as
            // "nothing ever changed".
            $key === null
                ? throw new SettingGroupNotFoundException($group)
                : throw new UnknownSettingKeyException($group, $key);
        }

        $byId = $settings->keyBy('id');

        $revisions = SettingRevision::query()
            ->whereIn('setting_id', $byId->keys())
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();

        return $revisions->map(function (SettingRevision $revision) use ($byId): array {
            /** @var Setting $setting */
            $setting = $byId->get($revision->setting_id);

            return [
                'key' => $setting->key,
                'locale' => $revision->locale,
                'version' => $revision->version,
                // Cast through the setting's declared type, so history reads the way
                // the value itself reads rather than as the raw stored string.
                'value' => $revision->value === null
                    ? null
                    : Setting::castValue($revision->value, $setting->type),
                'actor_id' => $revision->actor_id,
                'recorded_at' => $revision->created_at->toIso8601String(),
            ];
        })->all();
    }

    /**
     * An opaque validator for a group's current state (ADR 0038).    /**
     * An opaque validator for a group's current state (ADR 0038).
     *
     * A client reads it with the group and returns it with an update; a write built
     * on a stale read is refused rather than applied. Opaque on purpose — the
     * contract is *return what you were given*, so how it is computed can change
     * without every client changing with it.
     *
     * It covers the translations as well as the rows. A localized write touches only
     * `setting_translations` and leaves `settings.updated_at` alone, so a version
     * derived from the rows would let two administrators edit the same Arabic site
     * name and never conflict — the exact loss this exists to prevent.
     *
     * @throws SettingGroupNotFoundException
     */
    public function groupVersion(string $group): string
    {
        // The query builder rather than Eloquent: this is an aggregate, not a model,
        // and asking Eloquent for one means describing columns Setting does not have.
        $rows = DB::table('settings')
            ->where('group', $group)
            ->selectRaw('count(*) as row_count, coalesce(sum(version), 0) as version_sum')
            ->first();

        if ($rows === null || (int) $rows->row_count === 0) {
            throw new SettingGroupNotFoundException($group);
        }

        // Counted rather than timed, and carrying no value: the sum moves whenever any
        // row in the group is written, the count moves when the group's shape changes,
        // and neither exposes anything about what is stored.
        return substr(hash('sha256', implode('|', [
            $group,
            (string) $rows->row_count,
            (string) $rows->version_sum,
        ])), 0, 32);
    }

    /**
     * Invalidate cached settings.
     */
    public function clearCache(?string $group = null): void
    {
        // A change with no named group is a bulk one — a synchronisation, a language
        // activated, a restore. Bumping the namespace generation invalidates every
        // settings entry at once without enumerating anything and without reaching a
        // key outside this namespace, which is what ADR 0035 puts in place of a flush.
        if ($group === null) {
            $this->cache->flushNamespace(CacheNamespace::SETTINGS);

            return;
        }

        // One group changed, so the affected entries are known and few: forget them
        // precisely rather than discarding the rest of the namespace with them.
        $this->cache->forget(CacheNamespace::SETTINGS, self::RESOURCE_PUBLIC_GROUPS);

        // Every locale variant, not only the caller's. A stale entry in a language
        // nobody happened to request is exactly the one that will be served next
        // (ADR 0018), and the caller's own locale is rarely the one at risk.
        foreach ($this->cacheLocales() as $locale) {
            $this->cache->forget(CacheNamespace::SETTINGS, self::RESOURCE_PUBLIC, ['locale' => $locale]);
            $this->cache->forget(CacheNamespace::SETTINGS, self::RESOURCE_PUBLIC_GROUP, ['group' => $group, 'locale' => $locale]);
            $this->cache->forget(CacheNamespace::SETTINGS, self::RESOURCE_GROUP_INDEX, ['group' => $group, 'locale' => $locale]);
        }
    }

    /**
     * The locale a read resolves against.
     *
     * Read from the application rather than the resolver so that a caller which has
     * already negotiated a locale — every request has, through SetLocale — is not
     * made to negotiate it again per setting lookup.
     */
    protected function locale(): string
    {
        return app()->getLocale();
    }

    /**
     * Every locale a cache entry may exist under.
     *
     * The active set plus the current and default locales: a language deactivated
     * between a write and this call still has entries, and forgetting a key that was
     * never written costs nothing while missing one serves a stale value.
     *
     * @return array<int, string>
     */
    protected function cacheLocales(): array
    {
        $resolver = app(LocaleResolverInterface::class);

        return array_values(array_unique(array_merge(
            $resolver->getActiveLanguageCodes(),
            [$resolver->getDefaultLocale(), app()->getLocale(), (string) config('app.fallback_locale')],
        )));
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
    /**
     * @return Builder<Setting>
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
        return $this->cache->remember(CacheNamespace::SETTINGS, self::RESOURCE_PUBLIC_GROUPS, [], function (): array {
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
        $locale = $this->locale();
        $discriminators = ['group' => $group, 'locale' => $locale];
        $cached = $this->cache->get(CacheNamespace::SETTINGS, self::RESOURCE_GROUP_INDEX, $discriminators);

        // A cache entry written by an older revision can have a different shape. Treat
        // anything that does not match the current contract as a miss and rebuild it,
        // rather than letting a stale entry fault every read until the cache is purged.
        if (is_array($cached) && is_array($cached['values'] ?? null) && is_array($cached['secrets'] ?? null)) {
            return $cached;
        }

        $values = [];
        $secrets = [];

        foreach (Setting::query()->where('group', $group)->with('translations')->get() as $record) {
            if ($record->is_secret) {
                $secrets[] = $record->key;

                continue;
            }

            $values[$record->key] = $record->getTypedValue($locale);
        }

        $index = ['values' => $values, 'secrets' => $secrets];

        $this->cache->put(CacheNamespace::SETTINGS, self::RESOURCE_GROUP_INDEX, $discriminators, $index);

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
     * Keep what a non-secret setting was, before it stops being that (ADR 0040).
     *
     * A secret returns before a row is constructed. That is the same structural shape
     * the audit redaction uses and for the same reason: there is no path here that
     * could carry credential material, so there is nothing to remember to omit.
     *
     * A write that changes nothing writes no revision. History is read to answer what
     * a value used to be, and filling it with entries where the answer is "the same"
     * makes that question harder to answer, not easier.
     */
    private function recordRevision(Setting $setting, ?string $incoming): void
    {
        if ($setting->is_secret) {
            return;
        }

        $locale = $setting->is_localized ? $this->locale() : null;
        $previous = $this->previousStoredValue($setting, $locale);

        if ($previous === $incoming) {
            return;
        }

        SettingRevision::query()->create([
            'setting_id' => $setting->id,
            'version' => $setting->version,
            'locale' => $locale,
            'value' => $previous,
            'actor_id' => $this->actorId(),
        ]);
    }

    /**
     * The value being superseded, for the locale being written.
     *
     * For a localized setting this is the translation for that locale and not the
     * fallback: a locale with no translation of its own was holding nothing, and
     * recording the base value would let a later rollback create a translation that
     * never existed.
     */
    private function previousStoredValue(Setting $setting, ?string $locale): ?string
    {
        if ($locale === null) {
            return $setting->getRawValue();
        }

        $translation = $setting->translations->firstWhere('locale', $locale);

        $value = $translation?->getAttribute('value');

        return is_string($value) ? $value : null;
    }

    /**
     * The acting user's identifier, where a request has one.
     *
     * The id rather than the model, resolved now rather than by later lookup, so a
     * revision survives the account being deleted.
     */
    private function actorId(): ?string
    {
        $id = Auth::id();

        return is_string($id) ? $id : null;
    }

    /**
     * Record one setting change, without the trail ever seeing a secret.
     *
     * The redaction is structural rather than a filter applied afterwards. For a
     * secret this method has no branch that can reach a value: it decides between
     * set, rotated and cleared from whether the column was null before and after,
     * and passes the key alone. There is nothing to forget to omit.
     *
     * A non-secret setting records that it changed, not what to — the previous value
     * of a public setting is recoverable from the row's own history and is not what
     * the trail exists to answer. What it answers is who changed what, and when.
     */
    private function recordChange(Setting $setting, ?string $serialized, ?string $previousValue): void
    {
        $reference = $setting->group.'.'.$setting->key;

        if ($setting->is_secret) {
            $action = match (true) {
                $serialized === null => AuditAction::SECRET_CLEARED,
                $previousValue === null => AuditAction::SECRET_SET,
                default => AuditAction::SECRET_ROTATED,
            };

            // Key only. No plaintext, no ciphertext, no hash, no length — a ciphertext
            // here would be a second copy of the credential under weaker access
            // control than the settings table itself (ADR 0037).
            $this->audit->succeeded($action, $reference);

            return;
        }

        $this->audit->succeeded(
            $serialized === null ? AuditAction::SETTING_CLEARED : AuditAction::SETTING_UPDATED,
            $reference,
            ['type' => $setting->type->value, 'localized' => $setting->is_localized],
        );
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
            // Present for every setting rather than only localized ones, so a client
            // never has to branch on the flag to know what it is looking at.
            'is_localized' => $setting->is_localized,
            'locale' => $setting->is_localized ? app()->getLocale() : null,
            'type' => $setting->type->value,
            // Beside the value, never instead of it (ADR 0030/0031). This payload
            // is built per request and is not cached, so the label follows the
            // caller's locale rather than whoever asked first.
            'type_label' => $setting->type->label(),
            'is_secret' => $setting->is_secret,
            'is_public' => $setting->is_public,
            'description' => $setting->description,
            'updated_at' => $setting->updated_at?->toIso8601String(),
        ];
    }
}
