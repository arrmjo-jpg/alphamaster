<?php

declare(strict_types=1);

namespace App\Modules\Settings\Services;

use App\Modules\Core\Audit\AuditAction;
use App\Modules\Core\Cache\CacheNamespace;
use App\Modules\Core\Contracts\AuditRecorderContract;
use App\Modules\Core\Contracts\LocaleResolverInterface;
use App\Modules\Core\Contracts\PlatformCacheContract;
use App\Modules\Settings\Contracts\SettingServiceInterface;
use App\Modules\Settings\Definitions\SettingDefinition;
use App\Modules\Settings\Definitions\SettingRegistry;
use App\Modules\Settings\Exceptions\SettingGroupNotFoundException;
use App\Modules\Settings\Exceptions\UnknownRevisionException;
use App\Modules\Settings\Exceptions\UnknownSettingKeyException;
use App\Modules\Settings\Models\Setting;
use App\Modules\Settings\Models\SettingRevision;
use App\Modules\Settings\Rollback\RollbackChange;
use App\Modules\Settings\Rollback\RollbackPlan;
use App\Modules\Settings\Rollback\RollbackSkip;
use App\Modules\Settings\Rollback\RollbackSkipReason;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
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
        private readonly SettingRegistry $registry,
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
     * Replace a stored credential, once something has decided it may be replaced.
     *
     * Separate from `updateGroup` because a rotation is a different operation with a
     * different contract (ADR 0038, as extended): the candidate has already been tried
     * against the vendor where one exists, and the outcome of that travels into the
     * audit record so the trail distinguishes a rotation that was confirmed from one
     * that nobody could confirm.
     *
     * What it does not do is decide. The verification is performed before this is
     * called, by a service that owns verifiers, and a failed one never reaches here —
     * so there is no branch in the write path that could commit an unverified
     * credential by taking the wrong turn.
     *
     * No revision is written, here or anywhere: a secret has no history by design
     * (ADR 0040), so a rotated credential is unrecoverable, which is the property that
     * makes the revision store safe to keep.
     *
     * @throws UnknownSettingKeyException when nothing by that name is a secret here
     */
    public function rotateSecret(string $group, string $key, string $candidate, string $verification): void
    {
        DB::transaction(function () use ($group, $key, $candidate, $verification): void {
            $setting = Setting::query()->where('group', $group)->where('key', $key)->first();

            if ($setting === null || ! $setting->is_secret) {
                // A non-secret answers the same way a missing one does. Rotation is
                // defined for credentials, and telling a caller that some key exists
                // but is not secret is a fact about the catalogue they can already
                // read through the definitions endpoint.
                throw new UnknownSettingKeyException($group, $key);
            }

            // Whether it held anything before, which is the whole of what the trail is
            // allowed to know about a credential's previous state.
            $held = $setting->value !== null;

            $setting->version = $setting->version + 1;
            $setting->setRawValue($candidate);
            $setting->save();

            // Key, outcome, and nothing else. No plaintext, no ciphertext, no hash, no
            // length: a rotation record that carried any of those would be a second
            // copy of the credential under weaker access control than the settings
            // table it came from (ADR 0037).
            $this->audit->succeeded(
                $held ? AuditAction::SECRET_ROTATED : AuditAction::SECRET_SET,
                $group.'.'.$key,
                ['verification' => $verification],
            );

            DB::afterCommit(function () use ($group): void {
                $this->clearCache($group);
            });
        });
    }

    /**
     * Decide what rolling a group back to a point in its history would do (ADR 0040).
     *
     * The target is a revision rather than a version number, because `settings.version`
     * counts writes to one row and a group has as many counters as it has settings —
     * "roll the group back to version 4" names four different states. A revision
     * identifier is a ULID, so it is both unique and totally ordered, and ordering is
     * exactly what a point in time needs. It also cannot collide the way a timestamp
     * can: `timestampTz` stores whole seconds, which already produced one wrong answer
     * in this module (ADR 0038).
     *
     * The result is *the state the group held immediately before that revision was
     * recorded*. A revision holds the value its write superseded, so for each setting
     * the earliest revision at or after the target carries the value that setting had
     * at that moment; a setting with no revision since then has not changed and is
     * left alone rather than rewritten with the value it already has.
     *
     * Nothing is written here. Planning is separated from applying because the caller
     * cannot authorize a rollback until it knows which settings it touches, and a
     * rollback names a point in time rather than a list of keys — the keys are derived
     * from history. It also keeps the fallible part, which reads history and
     * revalidates against today's declarations, outside the write transaction.
     *
     * @throws SettingGroupNotFoundException when the group has no settings
     * @throws UnknownRevisionException when the target is unknown or belongs elsewhere
     */
    public function planRollback(string $group, string $revisionId): RollbackPlan
    {
        $settings = $this->groupSettings($group);
        $byId = $settings->keyBy('id');

        // Both "no such revision" and "a revision from another group" answer the same
        // way, so holding rollback on one group cannot be used to probe which
        // identifiers exist in another.
        $target = SettingRevision::query()->whereKey($revisionId)->first();

        if ($target === null || ! $byId->has($target->setting_id)) {
            throw new UnknownRevisionException($group, $revisionId);
        }

        $changes = [];
        $skipped = [];

        foreach ($this->stateAt($byId->keys()->all(), $revisionId) as [$settingId, $locale, $value]) {
            /** @var Setting $setting */
            $setting = $byId->get($settingId);

            // Already holding it. Not a skip and not a change: reporting it would pad
            // the response, and writing it would advance the version — invalidating
            // every client's cached read to store the value that was already there.
            if ($this->previousStoredValue($setting, $locale) === $value) {
                continue;
            }

            $reason = $this->rollbackRefusal($setting, $value);

            if ($reason !== null) {
                $skipped[] = new RollbackSkip($setting->key, $locale, $reason);

                continue;
            }

            $changes[] = new RollbackChange(
                $setting->key,
                $locale,
                $value,
                $value === null ? null : Setting::castValue($value, $setting->type),
            );
        }

        // Every credential in the group, whether or not it moved. A secret has no
        // revision to be restored from and never will (ADR 0040), and an operator
        // needs that as a checklist rather than as a surprise — so it is reported by
        // key here rather than being absent because nothing selected it.
        foreach ($settings as $setting) {
            if ($setting->is_secret) {
                $skipped[] = new RollbackSkip($setting->key, null, RollbackSkipReason::SECRET);
            }
        }

        [$changes, $skipped] = $this->withSatisfiedDependencies($settings, $changes, $skipped);

        return new RollbackPlan($group, $revisionId, $changes, $skipped);
    }

    /**
     * Apply a plan, as one transaction and as a new change (ADR 0040).
     *
     * A rollback does not rewrite history: it writes the old values as a *new* version,
     * recording revisions of its own along the way, so rolling a rollback back is an
     * ordinary rollback and an operator can see that one happened at all.
     *
     * The plan is applied exactly as it was decided. It is not recomputed here, because
     * the caller authorized the plan it was given, and re-deriving it inside the
     * transaction would apply something nobody approved. What keeps the plan current is
     * the ordinary precondition the caller checks first (ADR 0038).
     */
    public function applyRollback(RollbackPlan $plan): void
    {
        DB::transaction(function () use ($plan): void {
            $settings = $this->groupSettings($plan->group)->keyBy('key');

            // A plan with nothing to write still records that it was run and why it
            // restored nothing — the case where the reasons are most worth having.
            // What it must not do is advance the version, which would invalidate every
            // client's cached read to store values that were already there.
            if ($plan->isEmpty()) {
                $this->recordRollback($plan);

                return;
            }

            foreach ($plan->keys() as $key) {
                /** @var Setting|null $setting */
                $setting = $settings->get($key);

                if ($setting === null) {
                    // The group changed shape between planning and applying. Refusing
                    // is the only honest answer: the plan describes a setting that is
                    // no longer there, and the transaction takes the rest with it.
                    throw new UnknownSettingKeyException($plan->group, $key);
                }

                // Captured once per setting, before its counter advances, so every
                // locale written in this rollback records the version it superseded.
                $version = $setting->version;
                $setting->version = $version + 1;

                foreach ($plan->changes as $change) {
                    if ($change->key !== $key) {
                        continue;
                    }

                    $this->recordRevisionAt($setting, $change->locale, $change->value, $version);

                    $change->locale === null
                        ? $setting->setRawValue($change->value)
                        : $setting->setLocalizedValue($change->locale, $change->value);
                }

                $setting->save();
            }

            $this->recordRollback($plan);

            // After the commit, never inside it: clearing early lets a concurrent
            // reader repopulate from pre-commit state and pin it for a full TTL.
            DB::afterCommit(function () use ($plan): void {
                $this->clearCache($plan->group);
            });
        });
    }

    /**
     * The settings in a group, with their translations.
     *
     * Eager-loaded because every caller here reads per-locale values and lazy loading
     * is disabled platform-wide — which is how that was caught rather than becoming a
     * query per setting.
     *
     * @return EloquentCollection<int, Setting>
     *
     * @throws SettingGroupNotFoundException
     */
    private function groupSettings(string $group): EloquentCollection
    {
        /** @var EloquentCollection<int, Setting> $settings */
        $settings = Setting::query()->where('group', $group)->with('translations')->get();

        if ($settings->isEmpty()) {
            throw new SettingGroupNotFoundException($group);
        }

        return $settings;
    }

    /**
     * What each setting held at the moment a revision was recorded.
     *
     * One query rather than one per setting. ULIDs sort lexicographically in the same
     * order they were generated, so "at or after the target" is a plain range scan, and
     * the earliest row for each setting-and-locale pair is the value that pair held at
     * that moment — because a revision stores the value its write superseded.
     *
     * A pair with no revision at or after the target does not appear, which is correct:
     * nothing has changed it since, so there is nothing to restore.
     *
     * @param  array<int, mixed>  $settingIds
     * @return list<array{0: string, 1: string|null, 2: string|null}>
     */
    private function stateAt(array $settingIds, string $revisionId): array
    {
        $revisions = SettingRevision::query()
            ->whereIn('setting_id', $settingIds)
            ->where('id', '>=', $revisionId)
            ->orderBy('id')
            ->get();

        $state = [];

        foreach ($revisions as $revision) {
            // Keyed by setting and locale: a localized write replaces one language and
            // leaves the others, so each language has a history of its own.
            $pair = $revision->setting_id.'|'.($revision->locale ?? '');

            // Earliest wins. The rows arrive in order, so the first one seen for a pair
            // is the state at the target, and every later one describes a change made
            // after it.
            if (! array_key_exists($pair, $state)) {
                $state[$pair] = [$revision->setting_id, $revision->locale, $revision->value];
            }
        }

        return array_values($state);
    }

    /**
     * Why a stored value cannot be put back, or null when it can (ADR 0040).
     *
     * Validated against the declaration **as it exists today**, not as it existed when
     * the value was written. A setting may have been retyped, its bounds tightened, or
     * the media it points at deleted; restoring a value the running platform would
     * reject produces a configuration nothing can read, which is a worse outcome than
     * declining to restore it and saying so.
     */
    private function rollbackRefusal(Setting $setting, ?string $value): ?RollbackSkipReason
    {
        // Belt and braces: a secret has no revisions, so nothing should reach here with
        // one. If that ever stops being true, this refuses rather than writing it.
        if ($setting->is_secret) {
            return RollbackSkipReason::SECRET;
        }

        $definition = $this->definitionFor($setting);

        // A row with no declaration is an orphan the synchroniser reports and keeps
        // (ADR 0018). Reading it is fine; putting an old value back into something
        // nothing describes is not.
        if ($definition === null) {
            return RollbackSkipReason::UNDECLARED;
        }

        if (! $definition->editable) {
            return RollbackSkipReason::NOT_EDITABLE;
        }

        if ($value === null) {
            return $definition->nullable ? null : RollbackSkipReason::INVALID_TODAY;
        }

        try {
            $typed = Setting::castValue($value, $definition->type);
        } catch (InvalidArgumentException) {
            return RollbackSkipReason::TYPE_CHANGED;
        }

        return $this->violatesDeclaredRules($definition, $typed)
            ? RollbackSkipReason::INVALID_TODAY
            : null;
    }

    /**
     * The declaration for a setting row, or null when nothing declares it.
     *
     * The registry raises for an unknown reference, which is right for the admin API —
     * a setting nobody declared cannot be created through it (ADR 0018) — and wrong
     * here, where an orphaned row is a case to report rather than an error to raise.
     */
    private function definitionFor(Setting $setting): ?SettingDefinition
    {
        $reference = $setting->group.'.'.$setting->key;

        return $this->registry->has($reference) ? $this->registry->get($reference) : null;
    }

    /**
     * Whether a value fails the rules its definition declares.
     *
     * These rules are checked here and not on the ordinary write path, which is a
     * difference worth naming rather than leaving to be discovered: an ordinary write
     * carries a value an operator is looking at as they submit it, while a rollback
     * writes values from a state nobody has inspected, recorded under declarations that
     * may no longer hold. ADR 0040 requires the second to be revalidated; extending the
     * same enforcement to the first changes a shipped contract and belongs to a slice
     * that can be reviewed as one.
     */
    private function violatesDeclaredRules(SettingDefinition $definition, mixed $typed): bool
    {
        if ($definition->rules === []) {
            return false;
        }

        return Validator::make(['value' => $typed], ['value' => $definition->rules])->fails();
    }

    /**
     * Drop restorations that would switch something on without its prerequisite.
     *
     * Checked against the state the rollback would produce rather than the state it
     * starts from, which is what ADR 0040 means by validating dependencies after the
     * restored values are applied — reached here without writing anything first.
     *
     * Where a dependent setting is itself being restored, that restoration is the one
     * dropped: it is the thing being switched on. Where it is not, the change that
     * empties its prerequisite is dropped instead, which leaves the prerequisite as it
     * is and satisfies the dependency. Repeated until stable, because dropping one
     * change can expose another, and bounded so a cyclic declaration cannot spin here.
     *
     * @param  EloquentCollection<int, Setting>  $settings
     * @param  list<RollbackChange>  $changes
     * @param  list<RollbackSkip>  $skipped
     * @return array{0: list<RollbackChange>, 1: list<RollbackSkip>}
     */
    private function withSatisfiedDependencies(EloquentCollection $settings, array $changes, array $skipped): array
    {
        $guard = count($changes) + 1;

        while ($guard-- > 0) {
            $resulting = $this->resultingValues($settings, $changes);
            $dropped = null;

            foreach ($settings as $setting) {
                $definition = $this->definitionFor($setting);

                if ($definition === null || $definition->dependsOn === []) {
                    continue;
                }

                // A setting that is not in effect has no prerequisites to satisfy — a
                // disabled watermark does not need an image.
                if (! $this->isInEffect($resulting[$definition->reference()] ?? null)) {
                    continue;
                }

                foreach ($definition->dependsOn as $dependency) {
                    if ($this->isInEffect($resulting[$dependency] ?? $this->currentValueOf($dependency))) {
                        continue;
                    }

                    $dropped = $this->plannedChangeFor($changes, $definition->key)
                        ?? $this->plannedChangeFor($changes, $this->keyOf($dependency));

                    // A violation this rollback did not cause and cannot fix — the
                    // group was already in that state — is left alone rather than
                    // blamed on the operator. Scanning continues, so a pre-existing
                    // one does not mask a violation this rollback would introduce.
                    if ($dropped !== null) {
                        break 2;
                    }
                }
            }

            if ($dropped === null) {
                return [$changes, $skipped];
            }

            $changes = array_values(array_filter(
                $changes,
                static fn (RollbackChange $change): bool => $change !== $dropped,
            ));

            $skipped[] = new RollbackSkip($dropped->key, $dropped->locale, RollbackSkipReason::DEPENDENCY_UNSATISFIED);
        }

        return [$changes, $skipped];
    }

    /**
     * The group's values as they would be once a set of changes is applied.
     *
     * Evaluated in the acting locale for a localized setting: a dependency asks whether
     * a capability is usable, and a capability is usable in the language it is being
     * configured in.
     *
     * @param  EloquentCollection<int, Setting>  $settings
     * @param  list<RollbackChange>  $changes
     * @return array<string, string|null>
     */
    private function resultingValues(EloquentCollection $settings, array $changes): array
    {
        $locale = $this->locale();
        $values = [];
        $groups = [];

        foreach ($settings as $setting) {
            $values[$setting->group.'.'.$setting->key] = $setting->is_localized
                ? $setting->getLocalizedRawValue($locale)
                : $setting->getRawValue();

            $groups[$setting->key] = $setting->group;
        }

        foreach ($changes as $change) {
            if ($change->locale === null || $change->locale === $locale) {
                $values[$groups[$change->key].'.'.$change->key] = $change->value;
            }
        }

        return $values;
    }

    /**
     * Whether a stored value counts as holding something usable.
     *
     * Null, the empty string and a stored `false` all mean "not set up", which is what
     * a prerequisite is asking about. Anything else is a value.
     */
    private function isInEffect(?string $value): bool
    {
        return $value !== null && $value !== '' && $value !== 'false';
    }

    /**
     * The current stored value of a reference outside the group being rolled back.
     *
     * A dependency may point anywhere, and one in another group is unaffected by this
     * rollback, so its value is read as it stands.
     */
    private function currentValueOf(string $reference): ?string
    {
        [$group, $key] = $this->splitKey($reference);

        $setting = Setting::query()->where('group', $group)->where('key', $key)->first();

        return $setting?->getLocalizedRawValue($this->locale());
    }

    /**
     * The key half of a 'group.key' reference.
     */
    private function keyOf(string $reference): string
    {
        return $this->splitKey($reference)[1];
    }

    /**
     * The first planned change for a key, or null when the key is not being changed.
     *
     * @param  list<RollbackChange>  $changes
     */
    private function plannedChangeFor(array $changes, string $key): ?RollbackChange
    {
        foreach ($changes as $change) {
            if ($change->key === $key) {
                return $change;
            }
        }

        return null;
    }

    /**
     * Record the rollback as one event (ADR 0037).
     *
     * One record rather than one per setting: a rollback is a single decision, and a
     * trail that splits it into twenty rows makes it harder to see that it happened.
     *
     * It carries what was restored and what was not, by key and by reason, and no
     * values at all — the audit trail holds none, and the revision store already
     * answers what the values were.
     */
    private function recordRollback(RollbackPlan $plan): void
    {
        $this->audit->succeeded(AuditAction::SETTINGS_ROLLED_BACK, $plan->group, [
            'target_revision_id' => $plan->targetRevisionId,
            'restored' => array_map(
                static fn (RollbackChange $change): string => $change->locale === null
                    ? $change->key
                    : $change->key.'@'.$change->locale,
                $plan->changes,
            ),
            'skipped' => array_map(
                static fn (RollbackSkip $skip): array => ['key' => $skip->key, 'reason' => $skip->reason->value],
                $plan->skipped,
            ),
            'version' => $this->groupVersion($plan->group),
        ]);
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

        $this->recordRevisionAt(
            $setting,
            $setting->is_localized ? $this->locale() : null,
            $incoming,
            $setting->version,
        );
    }

    /**
     * The same, for a named locale and a named version.
     *
     * A rollback restores several languages of one setting in a single write, so it
     * cannot take the locale from the request the way an ordinary write does, and it
     * bumps the row's counter once for all of them — so the version each revision
     * belongs to is passed in rather than read back off a model that has already moved.
     */
    private function recordRevisionAt(Setting $setting, ?string $locale, ?string $incoming, int $version): void
    {
        if ($setting->is_secret) {
            return;
        }

        $previous = $this->previousStoredValue($setting, $locale);

        if ($previous === $incoming) {
            return;
        }

        SettingRevision::query()->create([
            'setting_id' => $setting->id,
            'version' => $version,
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
