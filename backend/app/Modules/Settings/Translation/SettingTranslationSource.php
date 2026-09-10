<?php

declare(strict_types=1);

namespace App\Modules\Settings\Translation;

use App\Modules\Core\Translation\TranslationEntry;
use App\Modules\Core\Translation\TranslationField;
use App\Modules\Core\Translation\TranslationSource;
use App\Modules\Core\Translation\UnknownTranslationTargetException;
use App\Modules\Settings\Contracts\SettingServiceInterface;
use App\Modules\Settings\Definitions\SettingDefinition;
use App\Modules\Settings\Definitions\SettingRegistry;
use App\Modules\Settings\Models\Setting;

/**
 * The settings whose value is text somebody reads, offered for translation.
 *
 * A handful of settings are declared `isLocalized` — the site name, the strapline,
 * the address in the footer, the sender name on outgoing mail. Their value is not
 * configuration in the usual sense; it is copy, and copy in one language on a platform
 * that ships two is a gap nobody could see until now. The settings screen edits them
 * one locale at a time, in whichever language the console happens to be in, which is
 * how a value ends up written in English inside the Arabic column.
 *
 * Only localized settings appear. A number of days or a boolean has no translation,
 * and offering one would invite somebody to write a different retention period in
 * Arabic than in English.
 *
 * Writes go through the settings service exactly as the settings screen's do, so a
 * translation is versioned, audited and rolled back like any other change to a value.
 * Nothing here reaches the table directly.
 */
class SettingTranslationSource implements TranslationSource
{
    public function __construct(
        private readonly SettingRegistry $registry,
        private readonly SettingServiceInterface $settings,
    ) {}

    public function key(): string
    {
        return 'settings';
    }

    public function label(): string
    {
        return 'translations.sources.settings';
    }

    public function viewPermission(): ?string
    {
        return 'settings.view';
    }

    public function writePermission(): string
    {
        return 'settings.update';
    }

    public function entries(): array
    {
        $localized = $this->localizedDefinitions();

        if ($localized === []) {
            return [];
        }

        $rows = Setting::query()
            ->whereIn('group', array_values(array_unique(array_column($localized, 'group'))))
            ->with('translations')
            ->get()
            ->keyBy(fn (Setting $setting): string => $setting->group.'.'.$setting->key);

        $entries = [];

        foreach ($localized as $reference => $definition) {
            /** @var Setting|null $row */
            $row = $rows->get($reference);

            if ($row === null) {
                // Declared but not yet materialised. The synchroniser writes rows from
                // definitions, so this is a platform mid-deployment rather than a
                // missing translation, and inventing an entry for it would offer an
                // editor a field whose write would fail.
                continue;
            }

            $entries[] = new TranslationEntry(
                id: $reference,
                title: $definition->label(),
                fields: [
                    new TranslationField(
                        name: 'value',
                        label: 'translations.fields.value',
                        values: $this->writtenValues($row),
                    ),
                ],
                context: $this->help($definition->helpKey()),
            );
        }

        return $entries;
    }

    public function write(string $id, string $locale, array $values): void
    {
        $definition = $this->localizedDefinitions()[$id] ?? null;

        if ($definition === null) {
            throw UnknownTranslationTargetException::item($this->key(), $id);
        }

        foreach (array_keys($values) as $field) {
            if ($field !== 'value') {
                throw UnknownTranslationTargetException::field($this->key(), (string) $field);
            }
        }

        if (! array_key_exists('value', $values)) {
            return;
        }

        // The same path the settings screen takes, with the locale named rather than
        // inherited from the request: an editor writing Arabic from an English console
        // must not have the text land in the console's language.
        $this->settings->updateGroup(
            $definition->group,
            [$definition->key => $values['value']],
            $locale,
        );
    }

    /**
     * What has actually been written per locale, with no fallback.
     *
     * A fallback is exactly the wrong thing here: it would show the English value in
     * the Arabic column and make an untranslated setting look finished.
     *
     * @return array<string, string>
     */
    private function writtenValues(Setting $setting): array
    {
        $values = [];

        foreach ($setting->translations as $translation) {
            $value = $translation->value;

            if (is_string($value) && $value !== '') {
                $values[$translation->locale] = $value;
            }
        }

        return $values;
    }

    /**
     * Every localized definition, keyed by `group.key`.
     *
     * @return array<string, SettingDefinition>
     */
    private function localizedDefinitions(): array
    {
        $localized = [];

        foreach ($this->registry->all() as $reference => $definition) {
            if ($definition->isLocalized && $definition->editable) {
                $localized[$reference] = $definition;
            }
        }

        return $localized;
    }

    /**
     * The help sentence, where one has been written for this setting.
     */
    private function help(string $key): ?string
    {
        $translated = __($key);

        return is_string($translated) && $translated !== $key ? $translated : null;
    }
}
