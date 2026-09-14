<?php

declare(strict_types=1);

namespace App\Modules\Team\Services;

use App\Modules\Core\Translation\TranslationProgress;
use App\Modules\Team\Models\TeamMemberTranslation;

/**
 * What a team member's profile must hold in a language (ADR 0055 §4): a name, a position and
 * an address. A biography is optional.
 */
final class TeamMemberContent
{
    /** @var list<string> */
    public const FIELDS = ['name', 'position', 'bio', 'slug'];

    /** @var list<string> */
    public const REQUIRED = ['name', 'position', 'slug'];

    /** The social profiles a member may link to. Addresses, the same in every language. */
    public const SOCIAL_NETWORKS = ['website', 'x', 'facebook', 'instagram', 'linkedin', 'youtube', 'tiktok', 'github', 'telegram'];

    public static function progress(?TeamMemberTranslation $translation): TranslationProgress
    {
        $values = [];

        foreach (self::FIELDS as $field) {
            $values[$field] = $translation?->getAttribute($field);
        }

        return TranslationProgress::of($values, self::REQUIRED);
    }

    public static function isComplete(?TeamMemberTranslation $translation): bool
    {
        return self::progress($translation)->complete;
    }
}
