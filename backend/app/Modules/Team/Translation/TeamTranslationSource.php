<?php

declare(strict_types=1);

namespace App\Modules\Team\Translation;

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
use App\Modules\Team\Enums\TeamPermission;
use App\Modules\Team\Models\TeamMember;
use App\Modules\Team\Services\TeamService;

/**
 * Team profiles, offered to the translation workshop (ADR 0055 §9).
 *
 * Written through the same service as the editor. The slug is not offered; a profile written
 * here without one is given one from the name.
 */
class TeamTranslationSource implements TranslationSource
{
    private const CONTENT = ['name', 'position', 'bio'];

    private const SEO = ['seo_title' => 'title', 'seo_description' => 'description'];

    public function __construct(
        private readonly TeamService $team,
        private readonly SeoMetaStore $seo,
        private readonly ContentLocales $locales,
    ) {}

    public function key(): string
    {
        return 'team';
    }

    public function label(): string
    {
        return 'translations.sources.team';
    }

    public function viewPermission(): ?string
    {
        return TeamPermission::VIEW->value;
    }

    public function writePermission(): string
    {
        return TeamPermission::UPDATE->value;
    }

    public function entries(): array
    {
        $entries = [];
        $default = $this->locales->default();

        foreach (TeamMember::query()->with('translations')->orderBy('sort_order')->orderBy('created_at')->get() as $member) {
            /** @var TeamMember $member */
            $values = array_fill_keys([...self::CONTENT, ...array_keys(self::SEO)], []);

            foreach ($member->translations as $translation) {
                foreach (self::CONTENT as $field) {
                    $text = $translation->getAttribute($field);

                    if (is_string($text) && trim($text) !== '') {
                        $values[$field][(string) $translation->getAttribute('locale')] = $text;
                    }
                }
            }

            foreach ($this->seo->all($member) as $locale => $fields) {
                foreach (self::SEO as $field => $property) {
                    $text = $fields->toArray()[$property];

                    if ($text !== null) {
                        $values[$field][$locale] = $text;
                    }
                }
            }

            $entries[] = new TranslationEntry(
                id: $member->id,
                title: $values['name'][$default] ?? (array_values($values['name'])[0] ?? $member->id),
                // A profile needs a name and a position; the biography and SEO are optional.
                // The slug is made from the name when the profile is written (ADR 0056).
                fields: [
                    new TranslationField('name', 'translations.fields.name', $values['name'], required: true, maxLength: 150),
                    new TranslationField('position', 'translations.fields.position', $values['position'], required: true, maxLength: 150),
                    new TranslationField('bio', 'translations.fields.bio', $values['bio'], multiline: true, required: false, type: FieldType::HTML),
                    new TranslationField('seo_title', 'translations.fields.seo_title', $values['seo_title'], required: false, group: FieldGroup::SEO, maxLength: 255),
                    new TranslationField('seo_description', 'translations.fields.seo_description', $values['seo_description'], multiline: true, required: false, group: FieldGroup::SEO, maxLength: 1000),
                ],
            );
        }

        return $entries;
    }

    public function write(string $id, string $locale, array $values): void
    {
        $member = TeamMember::query()->find($id);

        if ($member === null) {
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

        $seo = $seoChanges === []
            ? null
            : array_merge(($this->seo->for($member, $locale) ?? new SeoFields)->toArray(), $seoChanges);

        $actor = auth()->user()?->getAuthIdentifier();

        try {
            $this->team->writeTranslation($member, $locale, $content, $seo, is_string($actor) ? $actor : null);
        } catch (ContentRefusedException $e) {
            throw TranslationRefusedException::because($e->messageKey, array_map('strval', $e->details));
        }
    }
}
