<?php

declare(strict_types=1);

namespace App\Modules\Settings\Backup;

use App\Modules\Core\Backup\RestoreReport;
use App\Modules\Core\Contracts\ConfigurationPortabilityContract;
use App\Modules\Settings\Definitions\DefinitionValidator;
use App\Modules\Settings\Definitions\SettingDefinition;
use App\Modules\Settings\Definitions\SettingRegistry;
use App\Modules\Settings\Definitions\SettingSynchronizer;
use App\Modules\Settings\Models\Setting;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Settings and their per-locale values, in and out of a portable export (ADR 0039).
 *
 * **Nothing here decrypts.** Values are read straight from the `value` column, which
 * holds ciphertext for a secret. The model offers `getRawValue()` and `getTypedValue()`,
 * both of which open it — correct for every other caller and exactly wrong here — so
 * this reads through the query builder, where those helpers are not in reach at all.
 * The ciphertext is what travels, or the value is omitted and named.
 */
class SettingsPortability implements ConfigurationPortabilityContract
{
    public function __construct(
        private readonly SettingRegistry $registry,
        private readonly SettingSynchronizer $synchronizer,
        private readonly DefinitionValidator $validator,
    ) {}

    public function section(): string
    {
        return 'settings';
    }

    /**
     * After languages, before provider metadata: a per-locale value needs its language
     * to exist, and nothing in a provider's configuration depends on a setting.
     */
    public function order(): int
    {
        return 20;
    }

    /**
     * @return array{data: array<string, mixed>, omitted_secrets: array<int, string>}
     */
    public function export(bool $includeSecrets): array
    {
        $omitted = [];
        $rows = [];

        foreach (DB::table('settings')->orderBy('group')->orderBy('key')->get() as $row) {
            $reference = $row->group.'.'.$row->key;
            $isSecret = (bool) $row->is_secret;

            if ($isSecret && ! $includeSecrets) {
                // Named, and its value left behind. An operator restoring elsewhere gets
                // a checklist of what to re-supply rather than an integration that stops
                // working for reasons nobody can trace.
                $omitted[] = $reference;

                continue;
            }

            $rows[] = [
                'group' => $row->group,
                'key' => $row->key,
                'type' => $row->type,
                'is_secret' => $isSecret,
                'is_public' => (bool) $row->is_public,
                'is_localized' => (bool) $row->is_localized,
                // The stored column, untouched. For a secret this is ciphertext that was
                // never opened; for anything else it is the canonical stored string.
                'value' => $row->value,
            ];
        }

        return [
            'data' => [
                'rows' => $rows,
                'translations' => $this->translations(),
                // The declaration set as it stood, for a reader to compare against. A
                // restore does not install these — definitions come from the running
                // registry — but an operator inspecting an export needs to see what the
                // values were declared as when they were written.
                'definitions' => $this->definitions(),
            ],
            'omitted_secrets' => $omitted,
        ];
    }

    /**
     * @param  array<string, mixed>  $section
     */
    public function restore(array $section, bool $mayWriteEncrypted): RestoreReport
    {
        // Definitions from the running code, not from the export. The running code is
        // what will read these settings; an export from an older release describes
        // settings this deployment may no longer have (ADR 0039).
        $this->synchronizer->synchronise();

        $restored = 0;
        $skipped = [];

        DB::transaction(function () use ($section, $mayWriteEncrypted, &$restored, &$skipped): void {
            $existing = Setting::query()->get()->keyBy(fn (Setting $s): string => $s->group.'.'.$s->key);

            /** @var array<int, array<string, mixed>> $rows */
            $rows = is_array($section['rows'] ?? null) ? $section['rows'] : [];

            foreach ($rows as $row) {
                $reference = (string) ($row['group'] ?? '').'.'.(string) ($row['key'] ?? '');

                /** @var Setting|null $setting */
                $setting = $existing->get($reference);

                if ($setting === null || ! $this->registry->has($reference)) {
                    // Present in the export and not declared here. Writing it would
                    // create a row nothing describes and nothing reads.
                    $skipped[] = ['key' => $reference, 'reason' => 'undeclared'];

                    continue;
                }

                if ($setting->is_secret && ! $mayWriteEncrypted) {
                    // Ciphertext from a different key. Writing it produces a value that
                    // decrypts to nothing, and the failure surfaces later as an
                    // integration that stopped working (ADR 0039).
                    $skipped[] = ['key' => $reference, 'reason' => 'key_mismatch'];

                    continue;
                }

                $reason = $this->refusal($setting, $row);

                if ($reason !== null) {
                    $skipped[] = ['key' => $reference, 'reason' => $reason];

                    continue;
                }

                $setting->version = $setting->version + 1;
                $setting->setAttribute('value', $row['value']);
                $setting->save();

                $restored++;
            }

            $this->restoreTranslations($section, $existing, $skipped);
        });

        return RestoreReport::of($this->section(), $restored, $skipped);
    }

    /**
     * Why a stored value cannot be written here, or null when it can.
     *
     * Checked against the declaration as it exists today. A secret is exempt from the
     * type check because its stored form is ciphertext, which is not the declared type
     * and never was — validating it would mean decrypting it, which is the one thing
     * this class does not do.
     *
     * @param  array<string, mixed>  $row
     */
    private function refusal(Setting $setting, array $row): ?string
    {
        $value = $row['value'] ?? null;

        if ($value !== null && ! is_string($value)) {
            return 'malformed';
        }

        if ($setting->is_secret) {
            return null;
        }

        $definition = $this->registry->get($setting->group.'.'.$setting->key);

        if ($value === null) {
            return $definition->nullable ? null : 'not_nullable';
        }

        try {
            $typed = Setting::castValue($value, $definition->type);
        } catch (InvalidArgumentException) {
            // A value that no longer fits its declared type is reported and skipped,
            // never coerced. A coerced restore is a corruption nobody notices.
            return 'type_changed';
        }

        // And the rules, as of Phase 16B-6. ADR 0039 asked only for the type, which was
        // the strongest check available when it was written; leaving it there now would
        // let a restore install a value the ordinary write path refuses.
        return $this->validator->violates($definition, $typed) ? 'invalid_today' : null;
    }

    /**
     * Per-locale values, keyed by the setting they belong to.
     *
     * @return array<int, array<string, mixed>>
     */
    private function translations(): array
    {
        $settings = DB::table('settings')->select('id', 'group', 'key')->get()->keyBy('id');
        $rows = [];

        foreach (DB::table('setting_translations')->orderBy('setting_id')->orderBy('locale')->get() as $row) {
            $setting = $settings->get($row->setting_id);

            if ($setting === null) {
                continue;
            }

            $rows[] = [
                'group' => $setting->group,
                'key' => $setting->key,
                'locale' => $row->locale,
                'value' => $row->value,
            ];
        }

        return $rows;
    }

    /**
     * Write the per-locale values back, after their settings exist.
     *
     * A localized setting is never a secret by construction, so nothing here can be
     * ciphertext and the key fingerprint does not apply.
     *
     * @param  array<string, mixed>  $section
     * @param  Collection<string, Setting>  $existing
     * @param  array<int, array{key: string, reason: string}>  $skipped
     */
    private function restoreTranslations(array $section, mixed $existing, array &$skipped): void
    {
        /** @var array<int, array<string, mixed>> $rows */
        $rows = is_array($section['translations'] ?? null) ? $section['translations'] : [];

        foreach ($rows as $row) {
            $reference = (string) ($row['group'] ?? '').'.'.(string) ($row['key'] ?? '');

            /** @var Setting|null $setting */
            $setting = $existing->get($reference);
            $locale = $row['locale'] ?? null;

            if ($setting === null || ! $setting->is_localized || ! is_string($locale)) {
                $skipped[] = ['key' => $reference.'@'.(is_string($locale) ? $locale : '?'), 'reason' => 'undeclared'];

                continue;
            }

            $value = $row['value'] ?? null;
            $setting->setLocalizedValue($locale, is_string($value) ? $value : null);
            $setting->save();
        }
    }

    /**
     * The declaration set, as data.
     *
     * @return array<int, array<string, mixed>>
     */
    private function definitions(): array
    {
        return array_values(array_map(static fn (SettingDefinition $d): array => [
            'reference' => $d->reference(),
            'type' => $d->type->value,
            'nullable' => $d->nullable,
            'is_secret' => $d->isSecret,
            'is_public' => $d->isPublic,
            'is_localized' => $d->isLocalized,
            'deprecated_since' => $d->deprecatedSince,
        ], $this->registry->all()));
    }
}
