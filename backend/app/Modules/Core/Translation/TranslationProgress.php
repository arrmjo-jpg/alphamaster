<?php

declare(strict_types=1);

namespace App\Modules\Core\Translation;

/**
 * How far one language's translation of one item has got, counted from what is saved
 * (ADR 0043 §4, ADR 0056).
 *
 * `filled` and `total` count every translatable field, so an operator sees "3 / 5". `complete`
 * is the owner's rule — every required field has text — and is not "every field": a page
 * without an SEO description is still a complete page.
 */
final readonly class TranslationProgress
{
    public function __construct(
        public int $filled,
        public int $total,
        public bool $complete,
    ) {}

    /**
     * @param  array<string, mixed>  $values  field => the value saved for it
     * @param  list<string>  $required
     */
    public static function of(array $values, array $required): self
    {
        $filled = 0;

        foreach ($values as $value) {
            if (is_string($value) && trim($value) !== '') {
                $filled++;
            }
        }

        $complete = true;

        foreach ($required as $field) {
            $value = $values[$field] ?? null;

            if (! is_string($value) || trim($value) === '') {
                $complete = false;
            }
        }

        return new self($filled, count($values), $complete);
    }

    /**
     * @return array{filled: int, total: int, complete: bool}
     */
    public function toArray(): array
    {
        return ['filled' => $this->filled, 'total' => $this->total, 'complete' => $this->complete];
    }
}
