<?php

declare(strict_types=1);

namespace App\Modules\Authorization\Translation;

use App\Modules\Authorization\Models\Role;
use App\Modules\Core\Translation\TranslationEntry;
use App\Modules\Core\Translation\TranslationField;
use App\Modules\Core\Translation\TranslationSource;
use App\Modules\Core\Translation\UnknownTranslationTargetException;

/**
 * Role labels, offered for translation.
 *
 * A role's identifier is immutable and is not language — `super_admin` is the same
 * string in every locale, which is what permissions and assignments reference. The
 * label is the part a person reads, and a role an administrator created carries it
 * relationally because it did not exist when the code shipped (ADR 0030).
 *
 * Built-in roles are here too, and their entries look empty on purpose. Their label
 * comes from a catalogue key when no translation has been written, so a blank Arabic
 * column means "nothing has been written for this role", not "this role has no name".
 * Writing one overrides the catalogue, which is the point: an operator who dislikes
 * the shipped wording should not have to edit a language file to change it.
 */
class RoleTranslationSource implements TranslationSource
{
    public function key(): string
    {
        return 'roles';
    }

    public function label(): string
    {
        return 'translations.sources.roles';
    }

    public function viewPermission(): ?string
    {
        return 'roles.view';
    }

    public function writePermission(): string
    {
        return 'roles.update';
    }

    public function entries(): array
    {
        $entries = [];

        /** @var Role $role */
        foreach (Role::query()->with('translations')->orderBy('name')->get() as $role) {
            $written = [];

            foreach ($role->translations as $translation) {
                $label = $translation->getAttribute('label');

                if (is_string($label) && $label !== '') {
                    $written[(string) $translation->getAttribute('locale')] = $label;
                }
            }

            $entries[] = new TranslationEntry(
                id: (string) $role->getKey(),
                // The identifier, deliberately: it is what the role *is*, and showing
                // the display label here would show a fallback beside the columns that
                // exist to reveal where a fallback is being relied on.
                title: (string) $role->name,
                fields: [
                    new TranslationField(
                        name: 'label',
                        label: 'translations.fields.label',
                        values: $written,
                    ),
                ],
            );
        }

        return $entries;
    }

    public function write(string $id, string $locale, array $values): void
    {
        /** @var Role|null $role */
        $role = Role::query()->find($id);

        if ($role === null) {
            throw UnknownTranslationTargetException::item($this->key(), $id);
        }

        foreach (array_keys($values) as $field) {
            if ($field !== 'label') {
                throw UnknownTranslationTargetException::field($this->key(), (string) $field);
            }
        }

        if (! array_key_exists('label', $values)) {
            return;
        }

        $label = $values['label'];

        if (! is_string($label) || trim($label) === '') {
            // Removed rather than emptied. The column is NOT NULL and the fallback is
            // what an operator is asking for: with no row, the label comes from the
            // catalogue again, or is humanised from the identifier.
            $role->translations()->where('locale', $locale)->delete();
            $role->unsetRelation('translations');

            return;
        }

        $role->setTranslation($locale, ['label' => $label]);
    }
}
