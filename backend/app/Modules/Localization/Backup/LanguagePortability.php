<?php

declare(strict_types=1);

namespace App\Modules\Localization\Backup;

use App\Modules\Core\Backup\RestoreReport;
use App\Modules\Core\Contracts\ConfigurationPortabilityContract;
use App\Modules\Localization\Models\Language;
use Illuminate\Support\Facades\DB;

/**
 * The languages a deployment serves, in and out of a portable export (ADR 0039).
 *
 * First in a restore, and not by preference. A per-locale setting value has nowhere to
 * be attached until its language exists, so restoring settings into a deployment whose
 * languages had not yet been created would silently drop every translation in the file.
 *
 * Holds no secret and never could: a language is a code, a name and a direction.
 */
class LanguagePortability implements ConfigurationPortabilityContract
{
    public function section(): string
    {
        return 'localization';
    }

    public function order(): int
    {
        return 10;
    }

    /**
     * @return array{data: array<string, mixed>, omitted_secrets: array<int, string>}
     */
    public function export(bool $includeSecrets): array
    {
        $languages = Language::query()
            ->orderBy('sort_order')
            ->orderBy('code')
            ->get()
            ->map(fn (Language $language): array => [
                'code' => $language->code,
                'name' => $language->name,
                'native_name' => $language->native_name,
                'direction' => $language->direction,
                'is_active' => $language->is_active,
                'is_default' => $language->is_default,
                'sort_order' => $language->sort_order,
            ])
            ->all();

        return ['data' => ['languages' => $languages], 'omitted_secrets' => []];
    }

    /**
     * Languages are created or updated, never removed.
     *
     * A restore that deleted languages absent from the export would take content with
     * them — every per-locale value in that language, in a store this section does not
     * own. Configuration transfer is not a synchronisation, and the destructive reading
     * of one is the reading nobody wants after the fact.
     *
     * @param  array<string, mixed>  $section
     */
    public function restore(array $section, bool $mayWriteEncrypted): RestoreReport
    {
        $restored = 0;
        $skipped = [];

        DB::transaction(function () use ($section, &$restored, &$skipped): void {
            /** @var array<int, array<string, mixed>> $rows */
            $rows = is_array($section['languages'] ?? null) ? $section['languages'] : [];

            foreach ($rows as $row) {
                $code = $row['code'] ?? null;

                if (! is_string($code) || $code === '') {
                    $skipped[] = ['key' => 'language', 'reason' => 'malformed'];

                    continue;
                }

                $direction = $row['direction'] ?? 'ltr';

                Language::query()->updateOrCreate(['code' => $code], [
                    'name' => (string) ($row['name'] ?? $code),
                    'native_name' => (string) ($row['native_name'] ?? $code),
                    'direction' => in_array($direction, ['ltr', 'rtl'], true) ? $direction : 'ltr',
                    'is_active' => (bool) ($row['is_active'] ?? true),
                    'is_default' => (bool) ($row['is_default'] ?? false),
                    'sort_order' => (int) ($row['sort_order'] ?? 0),
                ]);

                $restored++;
            }
        });

        return RestoreReport::of($this->section(), $restored, $skipped);
    }
}
