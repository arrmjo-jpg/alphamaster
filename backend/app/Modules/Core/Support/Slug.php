<?php

declare(strict_types=1);

namespace App\Modules\Core\Support;

use Illuminate\Support\Str;

/**
 * The part of an address a person reads (ADR 0055 §6).
 *
 * Letters in any script are kept, so an Arabic title gives an Arabic slug rather than an
 * empty or transliterated one. Everything that is not a letter or a digit becomes a single
 * hyphen. Latin letters are lower-cased; scripts without case are left as they are.
 *
 * Only when nothing usable is left does it fall back to a transliteration, and only when
 * that is empty too does it fall back to `item`, so a slug is never empty.
 */
final class Slug
{
    public const MAX_LENGTH = 190;

    private const PATTERN = '/^[\p{L}\p{N}]+(?:-[\p{L}\p{N}]+)*$/u';

    public static function make(string $value, int $maxLength = self::MAX_LENGTH): string
    {
        $slug = self::normalise($value, $maxLength);

        if ($slug === '') {
            $slug = self::normalise(Str::slug($value), $maxLength);
        }

        return $slug === '' ? 'item' : $slug;
    }

    /**
     * Whether a slug an operator typed is already in the form `make()` produces.
     */
    public static function isValid(string $slug, int $maxLength = self::MAX_LENGTH): bool
    {
        return $slug !== ''
            && mb_strlen($slug) <= $maxLength
            && $slug === mb_strtolower($slug)
            && preg_match(self::PATTERN, $slug) === 1;
    }

    private static function normalise(string $value, int $maxLength): string
    {
        $slug = mb_strtolower(trim($value));
        $slug = (string) preg_replace('/[^\p{L}\p{N}]+/u', '-', $slug);
        $slug = trim($slug, '-');

        if (mb_strlen($slug) > $maxLength) {
            $slug = rtrim(mb_substr($slug, 0, $maxLength), '-');
        }

        return $slug;
    }
}
