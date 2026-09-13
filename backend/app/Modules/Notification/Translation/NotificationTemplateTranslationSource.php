<?php

declare(strict_types=1);

namespace App\Modules\Notification\Translation;

use App\Modules\Core\Translation\TranslationEntry;
use App\Modules\Core\Translation\TranslationField;
use App\Modules\Core\Translation\TranslationRefusedException;
use App\Modules\Core\Translation\TranslationSource;
use App\Modules\Core\Translation\UnknownTranslationTargetException;
use App\Modules\Notification\Models\NotificationTemplate;

/**
 * The wording of every message the platform sends, offered for translation.
 *
 * This is the content where a missing translation is most visible and least
 * recoverable: a recipient whose account is set to Arabic and whose password-reset
 * mail arrives in English has been failed by the platform in the one message they
 * could not ignore. The template screen edits one template at a time and shows
 * whichever locale is being edited; nothing has ever answered "which of these has no
 * Arabic at all".
 *
 * Both fields travel together. A subject in one language over a body in another is a
 * worse result than either language alone, and an editor who can see the pair is the
 * simplest way to prevent it.
 */
class NotificationTemplateTranslationSource implements TranslationSource
{
    public function key(): string
    {
        return 'notification-templates';
    }

    public function label(): string
    {
        return 'translations.sources.notificationTemplates';
    }

    public function viewPermission(): ?string
    {
        return 'notifications.view';
    }

    public function writePermission(): string
    {
        return 'notifications.update';
    }

    public function entries(): array
    {
        $entries = [];

        /** @var NotificationTemplate $template */
        foreach (NotificationTemplate::query()->with('translations')->orderBy('type')->get() as $template) {
            $subjects = [];
            $bodies = [];

            foreach ($template->translations as $translation) {
                $locale = (string) $translation->getAttribute('locale');
                $subject = $translation->getAttribute('subject');
                $body = $translation->getAttribute('body');

                if (is_string($subject) && $subject !== '') {
                    $subjects[$locale] = $subject;
                }

                if (is_string($body) && $body !== '') {
                    $bodies[$locale] = $body;
                }
            }

            $entries[] = new TranslationEntry(
                id: (string) $template->getKey(),
                title: $template->type->value,
                fields: [
                    new TranslationField(
                        name: 'subject',
                        label: 'translations.fields.subject',
                        values: $subjects,
                    ),
                    new TranslationField(
                        name: 'body',
                        label: 'translations.fields.body',
                        values: $bodies,
                        multiline: true,
                    ),
                ],
            );
        }

        return $entries;
    }

    public function write(string $id, string $locale, array $values): void
    {
        /** @var NotificationTemplate|null $template */
        $template = NotificationTemplate::query()->find($id);

        if ($template === null) {
            throw UnknownTranslationTargetException::item($this->key(), $id);
        }

        foreach (array_keys($values) as $field) {
            if (! in_array($field, ['subject', 'body'], true)) {
                throw UnknownTranslationTargetException::field($this->key(), (string) $field);
            }
        }

        if ($values === []) {
            return;
        }

        // Merged over what is already stored, because an editor correcting only the
        // subject must not erase the body written beside it — and because the decision
        // below is about the state the write would leave, not about the write alone.
        $existing = $template->translations->firstWhere('locale', $locale);

        $subject = $this->resulting($values, $existing, 'subject');
        $body = $this->resulting($values, $existing, 'body');

        if ($subject === null && $body === null) {
            // Removed rather than emptied. Both columns are NOT NULL, and an absent row
            // is what "this template has no wording in this language" means everywhere
            // else in the platform.
            $template->translations()->where('locale', $locale)->delete();
            $template->unsetRelation('translations');

            return;
        }

        if ($subject === null || $body === null) {
            // A subject with no body is a message that cannot be sent, so it is refused
            // rather than stored. Emptying both is how a language is taken back.
            throw TranslationRefusedException::because(
                'api.error.translations.template_needs_both',
                ['locale' => $locale]
            );
        }

        $template->setTranslation($locale, ['subject' => $subject, 'body' => $body]);
    }

    /**
     * What one field would hold after this write: the incoming value where the write
     * names it, otherwise whatever is already stored. Null means "nothing".
     *
     * @param  array<string, string|null>  $values
     */
    private function resulting(array $values, ?object $existing, string $field): ?string
    {
        $value = array_key_exists($field, $values)
            ? $values[$field]
            : ($existing?->getAttribute($field));

        return is_string($value) && trim($value) !== '' ? $value : null;
    }
}
