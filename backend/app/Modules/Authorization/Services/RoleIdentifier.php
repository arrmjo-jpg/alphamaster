<?php

declare(strict_types=1);

namespace App\Modules\Authorization\Services;

use App\Modules\Authorization\Models\Role;
use Illuminate\Support\Str;

/**
 * Derives a role's machine identifier from the label an administrator typed.
 *
 * An identifier is generated once and never changes (ADR 0029 item 12), so the
 * label is only its source at creation. Renaming a role afterwards changes what
 * people read and nothing the system depends on — which is the whole point of
 * separating the two (ADR 0030).
 */
class RoleIdentifier
{
    /**
     * The grammar is `RoleRequest`'s, unchanged. There is one identifier format
     * on this platform and this does not introduce a second.
     */
    public const PATTERN = '/^[a-z][a-z0-9_]*$/';

    private const MAX_LENGTH = 100;

    /**
     * Normalise a label into the identifier grammar, or null if it cannot be.
     *
     * A label of only punctuation, or only characters `Str::ascii` cannot
     * transliterate, produces nothing usable. Returning null lets the caller
     * report that through the normal validation contract rather than inventing
     * an identifier unrelated to what was typed.
     */
    public function fromLabel(string $label): ?string
    {
        // Str::ascii is the transliteration this framework already provides, so
        // "Rédacteur" yields "redacteur" rather than being stripped to nothing.
        $candidate = Str::lower(Str::ascii($label));

        // Anything outside the grammar becomes a separator, which collapses and
        // then trims, so "Content  Editor!" and "content-editor" agree.
        $candidate = (string) preg_replace('/[^a-z0-9]+/', '_', $candidate);
        $candidate = (string) preg_replace('/_+/', '_', $candidate);
        $candidate = trim($candidate, '_');

        // The grammar requires a letter first. Leading digits are dropped rather
        // than prefixed with an invented character.
        $candidate = (string) preg_replace('/^[0-9_]+/', '', $candidate);
        $candidate = trim(mb_substr($candidate, 0, self::MAX_LENGTH), '_');

        if ($candidate === '' || preg_match(self::PATTERN, $candidate) !== 1) {
            return null;
        }

        return $candidate;
    }

    /**
     * The identifier a new role should carry, suffixed if it is already taken.
     *
     * `content_editor`, then `content_editor_2`, `content_editor_3`. Deterministic,
     * and never reuses or overwrites an existing role.
     */
    public function generateUnique(string $label): ?string
    {
        $base = $this->fromLabel($label);

        if ($base === null) {
            return null;
        }

        if (! $this->taken($base)) {
            return $base;
        }

        for ($suffix = 2; $suffix < 1000; $suffix++) {
            $candidate = $this->withSuffix($base, $suffix);

            if (! $this->taken($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Append the suffix, trimming the base first so the result stays inside the
     * column and the grammar.
     */
    private function withSuffix(string $base, int $suffix): string
    {
        $tail = '_'.$suffix;
        $room = self::MAX_LENGTH - mb_strlen($tail);

        return trim(mb_substr($base, 0, $room), '_').$tail;
    }

    private function taken(string $identifier): bool
    {
        return Role::query()->where('name', $identifier)->exists();
    }
}
