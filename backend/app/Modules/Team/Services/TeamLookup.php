<?php

declare(strict_types=1);

namespace App\Modules\Team\Services;

use App\Modules\Team\Models\TeamMember;

/**
 * What a public profile address resolves to (ADR 0055 §5, §6).
 */
final readonly class TeamLookup
{
    public const FOUND = 'found';

    public const REDIRECT = 'redirect';

    public const UNAVAILABLE = 'unavailable';

    public const MISSING = 'missing';

    /**
     * @param  list<string>  $availableLocales
     */
    private function __construct(
        public string $kind,
        public ?TeamMember $member = null,
        public ?string $slug = null,
        public array $availableLocales = [],
    ) {}

    public static function found(TeamMember $member): self
    {
        return new self(self::FOUND, $member);
    }

    public static function redirect(TeamMember $member, string $slug): self
    {
        return new self(self::REDIRECT, $member, $slug);
    }

    /**
     * @param  list<string>  $availableLocales
     */
    public static function unavailable(TeamMember $member, array $availableLocales): self
    {
        return new self(self::UNAVAILABLE, $member, null, $availableLocales);
    }

    public static function missing(): self
    {
        return new self(self::MISSING);
    }
}
