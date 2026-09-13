<?php

declare(strict_types=1);

namespace App\Modules\Localization\Controllers\Admin;

use App\Modules\Core\Contracts\EffectiveGrants;
use App\Modules\Core\Controllers\BaseApiController;
use App\Modules\Core\Translation\TranslationRegistry;
use App\Modules\Localization\Enums\SuggestionStatus;
use App\Modules\Localization\Models\Language;
use App\Modules\Localization\Models\TranslationSuggestion;
use App\Modules\Localization\Services\SuggestionCoordinator;
use App\Modules\Localization\Services\TranslationCoverage;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Where every language stands, for the Languages page (ADR 0048 §5).
 *
 * One call rather than one per language: coverage comes from the same calculation the
 * workshop uses, suggestion counts from the states the platform stores, and AI
 * availability from the capability itself — so the page shows what is true without
 * the operator needing `integrations.view` to learn whether AI is there.
 */
class TranslationOverviewController extends BaseApiController
{
    public function __construct(
        private readonly TranslationCoverage $coverage,
        private readonly TranslationRegistry $registry,
        private readonly SuggestionCoordinator $coordinator,
        private readonly EffectiveGrants $grants,
    ) {}

    /**
     * Coverage and AI progress for every language the platform knows.
     */
    #[Response(200, type: 'array{success: bool, data: array{source_locale: string|null, ai: array{available: bool, may_use: bool}, languages: array<int, array{code: string, coverage: array{total: int, translated: int}, suggestions: array{pending: int, ready: int, failed: int, accepted: int, dismissed: int}}>}}')]
    public function show(Request $request): JsonResponse
    {
        $permissions = $this->grants->permissionsFor($request->user());

        $codes = Language::query()->ordered()->pluck('code')->map(fn ($code): string => (string) $code)->all();
        $coverage = $this->coverage->overall($permissions, $codes);
        $suggestions = $this->suggestionCounts($permissions, $codes);

        return $this->successResponse([
            'source_locale' => Language::query()->where('is_default', true)->value('code'),
            'ai' => [
                'available' => $this->coordinator->available(),
                'may_use' => in_array('ai.use', $permissions, true),
            ],
            'languages' => array_map(fn (string $code): array => [
                'code' => $code,
                'coverage' => $coverage[$code],
                'suggestions' => $suggestions[$code],
            ], $codes),
        ]);
    }

    /**
     * Suggestions per language and state, over the content the caller may write.
     *
     * The same scope the suggestion list uses: a suggestion carries the source text, so
     * counting ones for content the caller cannot write would describe content they
     * were not granted.
     *
     * @param  array<int, string>  $permissions
     * @param  array<int, string>  $codes
     * @return array<string, array{pending: int, ready: int, failed: int, accepted: int, dismissed: int}>
     */
    private function suggestionCounts(array $permissions, array $codes): array
    {
        $empty = array_fill_keys(SuggestionStatus::values(), 0);
        $counts = array_fill_keys($codes, $empty);

        $writable = [];

        foreach ($this->registry->all() as $key => $source) {
            if (in_array($source->writePermission(), $permissions, true)) {
                $writable[] = (string) $key;
            }
        }

        if ($writable === []) {
            /** @var array<string, array{pending: int, ready: int, failed: int, accepted: int, dismissed: int}> $counts */
            return $counts;
        }

        $rows = TranslationSuggestion::query()
            ->toBase()
            ->selectRaw('locale, status, count(*) as aggregate')
            ->whereIn('source_key', $writable)
            ->groupBy('locale', 'status')
            ->get();

        foreach ($rows as $row) {
            $locale = (string) $row->locale;
            $status = (string) $row->status;

            if (isset($counts[$locale][$status])) {
                $counts[$locale][$status] = (int) $row->aggregate;
            }
        }

        /** @var array<string, array{pending: int, ready: int, failed: int, accepted: int, dismissed: int}> $counts */
        return $counts;
    }
}
