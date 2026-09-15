<?php

declare(strict_types=1);

namespace App\Modules\Pages\Enums;

use App\Modules\Core\Concerns\HasDisplayLabel;

/**
 * Where a page is in its life (ADR 0055 §1). Three states and no workflow.
 *
 * The status belongs to the page, not to a language. Whether a published page is visible in
 * a given language is a separate question its translations answer (§4).
 */
enum PageStatus: string
{
    use HasDisplayLabel;

    case DRAFT = 'draft';
    case PUBLISHED = 'published';
    case ARCHIVED = 'archived';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
