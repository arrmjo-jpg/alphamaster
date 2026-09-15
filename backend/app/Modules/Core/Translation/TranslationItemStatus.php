<?php

declare(strict_types=1);

namespace App\Modules\Core\Translation;

use App\Modules\Core\Concerns\HasDisplayLabel;

/**
 * Where one item stands in one language (ADR 0056).
 *
 * The item is the unit an operator works with. Three states come from what is written —
 * nothing, some of what is required, all of it — and three from a translation in progress:
 * being generated, waiting for review, failed. Optional fields never move an item out of
 * `translated`.
 */
enum TranslationItemStatus: string
{
    use HasDisplayLabel;

    case NOT_TRANSLATED = 'not_translated';
    case INCOMPLETE = 'incomplete';
    case PENDING = 'pending';
    case READY = 'ready';
    case TRANSLATED = 'translated';
    case FAILED = 'failed';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
