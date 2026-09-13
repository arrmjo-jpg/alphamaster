<?php

declare(strict_types=1);

namespace App\Modules\Localization\Enums;

use App\Modules\Core\Concerns\HasDisplayLabel;

/**
 * Where a proposed translation has got to.
 *
 * Five states, and the boundary that matters is between the middle one and the last
 * two: `ready` is a suggestion nobody has acted on, and a field showing one has *not*
 * changed. Nothing is written until somebody accepts (ADR 0044 §5), so an interface
 * can render these without ever implying the platform has already decided.
 */
enum SuggestionStatus: string
{
    use HasDisplayLabel;

    /** Queued. The vendor has not been asked yet, or is still answering. */
    case PENDING = 'pending';

    /** Text came back and is waiting for a person to read it. */
    case READY = 'ready';

    /** Nothing came back. The row keeps why, so the operator is not left guessing. */
    case FAILED = 'failed';

    /** A person read it and wrote it through — possibly after editing it. */
    case ACCEPTED = 'accepted';

    /** A person read it and did not want it. */
    case DISMISSED = 'dismissed';

    /**
     * Whether this suggestion is still awaiting a decision.
     */
    public function isOutstanding(): bool
    {
        return $this === self::PENDING || $this === self::READY;
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
