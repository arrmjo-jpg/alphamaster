<?php

declare(strict_types=1);

namespace App\Modules\Team\Services;

use App\Modules\Core\Content\ContentLocales;
use App\Modules\Team\Models\TeamMember;
use App\Modules\Team\Models\TeamMemberSlugHistory;
use App\Modules\Team\Models\TeamMemberTranslation;
use Illuminate\Support\Collection;

/**
 * The team directory as the public reads it (ADR 0055 §4, §5).
 *
 * A member is public in a language when they are active, the language is served, and their
 * profile there is complete. Nothing is read from another language.
 */
class TeamReader
{
    public function __construct(private readonly ContentLocales $locales) {}

    /**
     * @return Collection<int, TeamMember>
     */
    public function listIn(string $locale): Collection
    {
        return TeamMember::query()
            ->where('is_active', true)
            ->with('translations')
            ->orderBy('sort_order')
            ->orderBy('created_at')
            ->get()
            ->filter(static fn (TeamMember $member): bool => TeamMemberContent::isComplete($member->translationIn($locale)))
            ->values();
    }

    public function lookup(string $slug, string $locale): TeamLookup
    {
        /** @var TeamMemberTranslation|null $translation */
        $translation = TeamMemberTranslation::query()->where('locale', $locale)->where('slug', $slug)->first();

        if ($translation !== null) {
            $member = $this->active($translation->team_member_id);

            if ($member !== null) {
                return TeamMemberContent::isComplete($member->translationIn($locale))
                    ? TeamLookup::found($member)
                    : TeamLookup::unavailable($member, $this->availableLocales($member));
            }
        }

        /** @var TeamMemberSlugHistory|null $old */
        $old = TeamMemberSlugHistory::query()->where('locale', $locale)->where('slug', $slug)->first();

        if ($old !== null) {
            $member = $this->active($old->team_member_id);
            $current = $member?->translationIn($locale);

            if ($member !== null && TeamMemberContent::isComplete($current) && $current->slug !== $slug) {
                return TeamLookup::redirect($member, (string) $current->slug);
            }
        }

        $elsewhere = TeamMemberTranslation::query()
            ->where('slug', $slug)
            ->where('locale', '!=', $locale)
            ->pluck('team_member_id');

        foreach ($elsewhere as $memberId) {
            $member = $this->active((string) $memberId);

            if ($member === null) {
                continue;
            }

            $here = $member->translationIn($locale);

            return TeamMemberContent::isComplete($here)
                ? TeamLookup::redirect($member, (string) $here->slug)
                : TeamLookup::unavailable($member, $this->availableLocales($member));
        }

        return TeamLookup::missing();
    }

    /**
     * @return list<string>
     */
    public function availableLocales(TeamMember $member): array
    {
        return array_values(array_filter(
            $this->locales->served(),
            static fn (string $locale): bool => TeamMemberContent::isComplete($member->translationIn($locale)),
        ));
    }

    private function active(string $id): ?TeamMember
    {
        return TeamMember::query()->where('is_active', true)->with('translations')->find($id);
    }
}
