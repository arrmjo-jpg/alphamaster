<?php

declare(strict_types=1);

namespace App\Modules\Core\Translation;

/**
 * A body of translatable content, declared by whoever owns it.
 *
 * The platform translates three different kinds of thing — role labels, notification
 * wording, the handful of settings whose value is text a visitor reads — and each is
 * owned by a different module. Before this there was no way to ask "what is still
 * untranslated?" without visiting three screens and knowing to look, and no way at all
 * to see the English beside the Arabic while writing one.
 *
 * The contract lives in Core for the reason `RetentionPolicyContract` does: the
 * workshop is served by Localization, and Localization may not depend on Settings,
 * Authorization or Notification. Each of those declares a source instead, and the
 * registry never learns what module a source came from.
 *
 * Registration is explicit and static, like `SettingRegistry` — a source is a class a
 * developer wrote and bound at boot, not something discovered by scanning (ADR 0033).
 *
 * **The permissions are the point of the two permission methods.** A workshop that let
 * anyone who can reach it rewrite notification wording would be a way around
 * `notifications.update`, so the source names the permission its own module enforces
 * and the workshop asks the same question the owning module would.
 */
interface TranslationSource
{
    /**
     * Stable identity, used in the URL and as the React key. Never shown.
     */
    public function key(): string;

    /**
     * A translation key for what to call this body of content on screen.
     */
    public function label(): string;

    /**
     * The permission a caller must hold to read this content, or null where none is
     * required beyond the administrative perimeter.
     */
    public function viewPermission(): ?string;

    /**
     * The permission a caller must hold to write it.
     *
     * Never null: everything translatable here is content other people read, and
     * "anyone who reached the console" is not an authorization decision.
     */
    public function writePermission(): string;

    /**
     * Every translatable item this source owns, with what has been written so far.
     *
     * @return array<int, TranslationEntry>
     */
    public function entries(): array;

    /**
     * Write one item's fields in one locale.
     *
     * Given only the fields that changed. An unknown item or an unknown field is a
     * refusal rather than a silent no-op — the workshop addresses items by an id it
     * was handed, so a miss means the content moved underneath it.
     *
     * A null value clears the translation: the language falls back to the platform's
     * own wording again, which is a thing an editor must be able to undo doing.
     *
     * @param  array<string, string|null>  $values  field name => the text written for it
     *
     * @throws UnknownTranslationTargetException when the item or a field is not there
     * @throws TranslationRefusedException when the write would leave the content in a
     *                                     state its owner does not allow
     */
    public function write(string $id, string $locale, array $values): void;
}
