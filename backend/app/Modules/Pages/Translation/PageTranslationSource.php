<?php

declare(strict_types=1);

namespace App\Modules\Pages\Translation;

use App\Modules\Core\Content\ContentLocales;
use App\Modules\Core\Content\ContentRefusedException;
use App\Modules\Core\Seo\SeoFields;
use App\Modules\Core\Seo\SeoMetaStore;
use App\Modules\Core\Translation\FieldGroup;
use App\Modules\Core\Translation\FieldType;
use App\Modules\Core\Translation\TranslationEntry;
use App\Modules\Core\Translation\TranslationField;
use App\Modules\Core\Translation\TranslationRefusedException;
use App\Modules\Core\Translation\TranslationSource;
use App\Modules\Core\Translation\UnknownTranslationTargetException;
use App\Modules\Pages\Enums\PagePermission;
use App\Modules\Pages\Models\Page;
use App\Modules\Pages\Services\PageService;

/**
 * Static pages, offered to the translation workshop (ADR 0055 §9).
 *
 * The same text the page editor writes, through the same service, so a page translated here
 * counts in its status there and the rules — sanitising, the default-language guard — apply
 * to both. The slug is not offered: an address is set where its uniqueness is checked, and a
 * translation written here without one is given one from its title.
 */
class PageTranslationSource implements TranslationSource
{
    private const CONTENT = ['title', 'summary', 'body'];

    private const SEO = ['seo_title' => 'title', 'seo_description' => 'description'];

    public function __construct(
        private readonly PageService $pages,
        private readonly SeoMetaStore $seo,
        private readonly ContentLocales $locales,
    ) {}

    public function key(): string
    {
        return 'pages';
    }

    public function label(): string
    {
        return 'translations.sources.pages';
    }

    public function viewPermission(): ?string
    {
        return PagePermission::VIEW->value;
    }

    public function writePermission(): string
    {
        return PagePermission::UPDATE->value;
    }

    public function entries(): array
    {
        $entries = [];
        $default = $this->locales->default();

        foreach (Page::query()->with('translations')->orderBy('sort_order')->orderBy('created_at')->get() as $page) {
            /** @var Page $page */
            $values = array_fill_keys([...self::CONTENT, ...array_keys(self::SEO)], []);

            foreach ($page->translations as $translation) {
                foreach (self::CONTENT as $field) {
                    $text = $translation->getAttribute($field);

                    if (is_string($text) && trim($text) !== '') {
                        $values[$field][(string) $translation->getAttribute('locale')] = $text;
                    }
                }
            }

            foreach ($this->seo->all($page) as $locale => $fields) {
                foreach (self::SEO as $field => $property) {
                    $text = $fields->toArray()[$property];

                    if ($text !== null) {
                        $values[$field][$locale] = $text;
                    }
                }
            }

            $entries[] = new TranslationEntry(
                id: $page->id,
                title: $values['title'][$default] ?? (array_values($values['title'])[0] ?? $page->id),
                // What PageContent requires, and nothing more: a page needs a title and a
                // body; its summary and SEO are optional. The slug is not a field here — it is
                // an address, made from the title in the same language when the page is
                // written (ADR 0055 §6, ADR 0056).
                fields: [
                    new TranslationField('title', 'translations.fields.title', $values['title'], required: true, maxLength: 200),
                    new TranslationField('summary', 'translations.fields.summary', $values['summary'], multiline: true, required: false, maxLength: 1000),
                    new TranslationField('body', 'translations.fields.body', $values['body'], multiline: true, required: true, type: FieldType::HTML),
                    new TranslationField('seo_title', 'translations.fields.seo_title', $values['seo_title'], required: false, group: FieldGroup::SEO, maxLength: 255),
                    new TranslationField('seo_description', 'translations.fields.seo_description', $values['seo_description'], multiline: true, required: false, group: FieldGroup::SEO, maxLength: 1000),
                ],
            );
        }

        return $entries;
    }

    public function write(string $id, string $locale, array $values): void
    {
        $page = Page::query()->find($id);

        if ($page === null) {
            throw UnknownTranslationTargetException::item($this->key(), $id);
        }

        $content = [];
        $seoChanges = [];

        foreach ($values as $field => $value) {
            if (in_array($field, self::CONTENT, true)) {
                $content[$field] = $value;
            } elseif (array_key_exists($field, self::SEO)) {
                $seoChanges[self::SEO[$field]] = $value;
            } else {
                throw UnknownTranslationTargetException::field($this->key(), (string) $field);
            }
        }

        $seo = null;

        if ($seoChanges !== []) {
            $seo = array_merge(($this->seo->for($page, $locale) ?? new SeoFields)->toArray(), $seoChanges);
        }

        $actor = auth()->user()?->getAuthIdentifier();

        try {
            $this->pages->writeTranslation($page, $locale, $content, $seo, is_string($actor) ? $actor : null);
        } catch (ContentRefusedException $e) {
            throw TranslationRefusedException::because($e->messageKey, array_map('strval', $e->details));
        }
    }
}
