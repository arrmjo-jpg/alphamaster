<?php

declare(strict_types=1);

namespace App\Modules\Localization\Enums;

use App\Modules\Core\Concerns\HasDisplayLabel;

/**
 * Where the translation of one item into one language has got (ADR 0056).
 *
 * The status an operator sees. Its fields each have their own, and this is what they add up
 * to: processing while any is still being generated, failed if any did not come back, ready
 * only when every one did. Nothing is written until a person accepts (ADR 0044 §5).
 */
enum BatchStatus: string
{
    use HasDisplayLabel;

    /** At least one field is still being generated. */
    case PENDING = 'pending';

    /** Every field came back and the item waits for a person to review it. */
    case READY = 'ready';

    /** At least one field did not come back. The batch keeps why, and can be asked for again. */
    case FAILED = 'failed';

    /** A person reviewed it and wrote it through, possibly after editing it. */
    case ACCEPTED = 'accepted';

    /** A person decided against it. */
    case DISMISSED = 'dismissed';

    /**
     * Whether asking again would duplicate work already under way or waiting for review.
     */
    public function isOutstanding(): bool
    {
        return $this === self::PENDING || $this === self::READY;
    }

    /**
     * Whether the batch still has something to show in the workshop.
     */
    public function isOpen(): bool
    {
        return $this->isOutstanding() || $this === self::FAILED;
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * @return array<int, string>
     */
    public static function openValues(): array
    {
        return [self::PENDING->value, self::READY->value, self::FAILED->value];
    }
}
