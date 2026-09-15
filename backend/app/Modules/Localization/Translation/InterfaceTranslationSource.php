<?php

declare(strict_types=1);

namespace App\Modules\Localization\Translation;

use App\Modules\Core\Contracts\LocaleResolverInterface;
use App\Modules\Core\Translation\DeclaresSourceLocale;
use App\Modules\Core\Translation\TranslationEntry;
use App\Modules\Core\Translation\TranslationField;
use App\Modules\Core\Translation\TranslationRefusedException;
use App\Modules\Core\Translation\TranslationSource;
use App\Modules\Core\Translation\UnknownTranslationTargetException;
use App\Modules\Localization\Interface\InterfaceCatalogue;
use App\Modules\Localization\Services\PlaceholderFidelity;

/**
 * One catalogue of the platform's interface, offered to the translation workshop (ADR 0049).
 *
 * The same contract as every other body of content (ADR 0043, ADR 0056): items made of fields
 * with metadata, translated in batches, reviewed and accepted once. Nothing here knows what a
 * menu, a button or a validation message is. The catalogue's source file lists the keys; an item
 * is the keys under one parent — `modules`, `translations.item`, `validation::custom.settings` —
 * so a sidebar or a dialog is reviewed as one, and a key added in code is in its item the moment
 * it is deployed.
 *
 * Translated from the catalogue's own language, not the content default. A field has a language's
 * text when that language ships it, or when an operator wrote it against the source text as it
 * reads now; a translation written against older source text is shown to readers but counted as
 * not translated here, so it is translated again.
 */
final class InterfaceTranslationSource implements DeclaresSourceLocale, TranslationSource
{
    public const PERMISSION = 'interface.translate';

    public function __construct(
        private readonly InterfaceCatalogue $catalogue,
        private readonly string $name,
        private readonly LocaleResolverInterface $locales,
    ) {}

    public function key(): string
    {
        return 'interface-'.$this->name;
    }

    public function label(): string
    {
        return 'translations.sources.interface_'.$this->name;
    }

    public function viewPermission(): string
    {
        return self::PERMISSION;
    }

    public function writePermission(): string
    {
        return self::PERMISSION;
    }

    public function sourceLocale(): string
    {
        return $this->catalogue->sourceLocale();
    }

    public function entries(): array
    {
        $source = $this->catalogue->source($this->name);
        $sourceLocale = $this->sourceLocale();

        /** @var array<string, array<string, string>> $values */
        $values = [];

        foreach ($source as $key => $text) {
            $values[$key] = [$sourceLocale => $text];
        }

        $overlays = $this->catalogue->overlays($this->name);

        foreach ($this->locales->getKnownLocaleCodes() as $locale) {
            if ($locale === $sourceLocale) {
                continue;
            }

            foreach ($this->catalogue->shipped($this->name, $locale) as $key => $text) {
                if (isset($values[$key]) && trim($text) !== '') {
                    $values[$key][$locale] = $text;
                }
            }

            foreach ($overlays[$locale] ?? [] as $key => $row) {
                if (! isset($source[$key])) {
                    continue;
                }

                if (InterfaceCatalogue::isCurrent($source[$key], $row['hash'])) {
                    $values[$key][$locale] = $row['value'];
                } else {
                    // Written against source text that has since changed.
                    unset($values[$key][$locale]);
                }
            }
        }

        /** @var array<string, list<TranslationField>> $items */
        $items = [];

        foreach ($source as $key => $text) {
            $items[$this->itemOf($key)][] = new TranslationField(
                name: $key,
                label: $key,
                values: $values[$key],
                multiline: str_contains($text, "\n") || mb_strlen($text) > 90,
                required: true,
                meta: ['catalogue' => $this->name],
            );
        }

        ksort($items);

        $entries = [];

        foreach ($items as $item => $fields) {
            $entries[] = new TranslationEntry(id: $item, title: $item, fields: $fields);
        }

        return $entries;
    }

    public function write(string $id, string $locale, array $values): void
    {
        if ($locale === $this->sourceLocale()) {
            throw TranslationRefusedException::because('api.error.translations.interface_source_locale', ['locale' => $locale]);
        }

        if (! in_array($locale, $this->locales->getKnownLocaleCodes(), true)) {
            throw TranslationRefusedException::because('api.error.translations.unknown_locale', ['locale' => $locale]);
        }

        $source = $this->catalogue->source($this->name);

        if (! in_array($id, array_map(fn (string $key): string => $this->itemOf($key), array_keys($source)), true)) {
            throw UnknownTranslationTargetException::item($this->key(), $id);
        }

        foreach ($values as $key => $value) {
            $key = (string) $key;

            if (! isset($source[$key]) || $this->itemOf($key) !== $id) {
                throw UnknownTranslationTargetException::field($this->key(), $key);
            }

            if (is_string($value) && trim($value) !== '' && ! PlaceholderFidelity::preserved($source[$key], $value)) {
                throw TranslationRefusedException::because('api.error.translations.placeholders_changed', ['field' => $key]);
            }
        }

        $actor = auth()->user()?->getAuthIdentifier();

        $this->catalogue->write($this->name, $locale, $values, is_scalar($actor) ? (string) $actor : null);
    }

    /**
     * The item a key belongs to: its parent. A Laravel group line keeps its group; a JSON key that
     * is a sentence rather than a path belongs to the catalogue's loose messages.
     */
    private function itemOf(string $key): string
    {
        $group = '';
        $path = $key;

        if (str_contains($key, InterfaceCatalogue::GROUP_SEPARATOR)) {
            [$group, $path] = explode(InterfaceCatalogue::GROUP_SEPARATOR, $key, 2);
            $group .= InterfaceCatalogue::GROUP_SEPARATOR;
        } elseif (preg_match('/^[A-Za-z0-9_-]+(\.[A-Za-z0-9_-]+)+$/', $key) !== 1) {
            return 'messages';
        }

        $parent = strrpos($path, '.');

        if ($parent === false) {
            return $group === '' ? $path : rtrim($group, ':');
        }

        return $group.substr($path, 0, $parent);
    }
}
