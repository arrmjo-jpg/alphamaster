<?php

declare(strict_types=1);

namespace App\Modules\Localization\Enums;

enum LanguageDirection: string
{
    case LTR = 'ltr';
    case RTL = 'rtl';

    /**
     * Determine if direction is RTL.
     */
    public function isRtl(): bool
    {
        return $this === self::RTL;
    }

    /**
     * Determine if direction is LTR.
     */
    public function isLtr(): bool
    {
        return $this === self::LTR;
    }
}
