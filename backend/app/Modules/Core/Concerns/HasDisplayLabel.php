<?php

declare(strict_types=1);

namespace App\Modules\Core\Concerns;

use Illuminate\Support\Str;

/**
 * A human-readable label for a backed enum case (ADR 0030).
 *
 * The enum keeps its value and gains a way to be read. The two are different
 * things: `not_scanned` is contract — matched by clients, written to columns,
 * asserted in tests — and «لم يُفحص» is what a person reads. This trait supplies
 * the second without touching the first.
 *
 * No Arabic or English text lives in an enum. A case declares nothing but its
 * value; the wording is a translation key resolved against the request locale,
 * so the same case reads differently to two callers and identically to the
 * database. Putting the text here would put a per-language string in a place
 * that has no locale and cannot be re-read when the locale changes.
 *
 * The key is derived from the enum's own class name rather than declared, so it
 * cannot be invented at a call site and cannot drift from the case it describes:
 *
 *     App\Modules\Media\Enums\ScanStatus::NOT_SCANNED
 *         -> enum.media.scan_status.not_scanned
 *
 *     App\Modules\Notification\Enums\NotificationType::SECURITY_ALERT
 *         -> enum.notification.notification_type.security.alert
 *
 * There are no aliases, no manual maps and no exceptions — including for enums
 * whose name repeats their module, which keep the full name so that two enums
 * called `Type` in different modules cannot collide.
 */
trait HasDisplayLabel
{
    /**
     * The label for this case in the active locale.
     *
     * Falls back to the technical value when no translation exists. That is
     * deliberate and is what ADR 0030 asks for: an untranslated label shows the
     * identifier, which is visibly wrong and gets reported, where an empty
     * string would look like a rendering bug and a raw translation key would
     * look like nothing at all.
     */
    public function label(): string
    {
        $key = $this->translationKey();
        $translated = __($key);

        // Laravel returns the key unchanged when nothing matches it, in the
        // active locale or through the fallback chain.
        if (! is_string($translated) || $translated === $key) {
            return (string) $this->value;
        }

        return $translated;
    }

    /**
     * The translation key for this case.
     */
    public function translationKey(): string
    {
        return self::translationKeyPrefix().'.'.$this->value;
    }

    /**
     * Every case as a value/label pair, in declaration order.
     *
     * This is the catalogue shape ADR 0031 fixes for a client choosing from a
     * set rather than reading one field. The technical value is present and
     * unchanged; the label sits beside it.
     *
     * @return array<int, array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            static fn (self $case): array => [
                'value' => (string) $case->value,
                'label' => $case->label(),
            ],
            self::cases()
        );
    }

    /**
     * The key prefix shared by every case of this enum, derived from where the
     * enum lives: `enum.{module}.{enum name}`.
     *
     * Reading the module out of the namespace is what keeps the derivation
     * total. A hand-written prefix would be a second place for the name to live
     * and a first place for it to be wrong.
     */
    protected static function translationKeyPrefix(): string
    {
        $parts = explode('\\', static::class);
        $name = Str::snake((string) array_pop($parts));

        // App\Modules\{Module}\Enums\{Name}
        $module = Str::snake($parts[2] ?? 'app');

        return 'enum.'.$module.'.'.$name;
    }
}
