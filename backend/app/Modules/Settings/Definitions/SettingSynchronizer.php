<?php

declare(strict_types=1);

namespace App\Modules\Settings\Definitions;

use App\Modules\Settings\Contracts\SettingServiceInterface;
use App\Modules\Settings\Models\Setting;
use Illuminate\Support\Facades\DB;

/**
 * Materialises registry definitions into settings rows.
 *
 * The one thing this must never do is lose configuration. Definitions are code and
 * change with a deploy; values are an operator's and change with intent. So the
 * rules are asymmetric on purpose (ADR 0018, revised):
 *
 *   - a missing row is created, carrying the declared default;
 *   - an existing row has its declared attributes brought up to date;
 *   - an existing row's **value is never touched**, at all, ever;
 *   - a row with no definition is reported as an orphan and left exactly as it is;
 *   - a change that would make an existing value unreadable is refused and reported.
 *
 * Running it twice over an unchanged registry changes nothing, which is what makes
 * it safe on every deploy rather than a thing someone remembers to run.
 */
class SettingSynchronizer
{
    public function __construct(
        private readonly SettingRegistry $registry,
        private readonly SettingServiceInterface $settings,
    ) {}

    /**
     * Bring the settings table into line with the registry.
     *
     * The whole run is one transaction: a synchronisation that failed halfway would
     * leave the platform declaring settings it had only partly provisioned.
     */
    public function synchronise(): SynchronisationReport
    {
        $report = new SynchronisationReport;

        DB::transaction(function () use ($report): void {
            /** @var array<string, Setting> $rows */
            $rows = Setting::query()
                ->get()
                ->keyBy(static fn (Setting $s): string => $s->group.'.'.$s->key)
                ->all();

            foreach ($this->registry->active() as $reference => $definition) {
                if (! isset($rows[$reference])) {
                    $this->create($definition, $report);

                    continue;
                }

                $this->reconcile($rows[$reference], $definition, $report);
            }

            $this->reportOrphans($rows, $report);
        });

        // Definitions can change what a payload contains — a setting becoming public,
        // or a type changing how it casts — so the cache is dropped once the run has
        // committed, never inside it (ADR 0035).
        //
        // After the transaction returns rather than through DB::afterCommit(). This is
        // a top-level operation, so the work is committed by the time control gets
        // here; afterCommit() would additionally defer to an *enclosing* transaction,
        // and under a test's wrapping transaction that never commits it would never
        // run at all — leaving the cache holding the previous run's catalogue.
        if ($report->changedAnything()) {
            $this->settings->clearCache();
        }

        return $report;
    }

    /**
     * Create a row for a definition that has none.
     *
     * This is the only path that ever writes a value, and it writes the declared
     * default. A secret is created unset: a default would put credential material in
     * the codebase, and generating one is an operator action (ADR 0018).
     */
    private function create(SettingDefinition $definition, SynchronisationReport $report): void
    {
        $setting = new Setting([
            'group' => $definition->group,
            'key' => $definition->key,
            'type' => $definition->type,
            'is_secret' => $definition->isSecret,
            'is_public' => $definition->isPublic,
            'is_localized' => $definition->isLocalized,
        ]);

        $setting->setRawValue(
            $definition->isSecret ? null : Setting::serializeValue($definition->default, $definition->type)
        );

        $setting->save();

        $report->created($definition->reference());
    }

    /**
     * Bring one existing row's declared attributes up to date, and never its value.
     */
    private function reconcile(Setting $row, SettingDefinition $definition, SynchronisationReport $report): void
    {
        $reference = $definition->reference();

        // Flipping is_secret on a row that holds a value would make that value
        // unreadable: a plaintext read as ciphertext raises, and a ciphertext read as
        // plaintext hands a vendor its own encrypted form. Neither is recoverable by
        // the synchroniser, so it refuses and says why.
        if ($row->is_secret !== $definition->isSecret && $row->value !== null) {
            $report->conflict(
                $reference,
                $row->is_secret
                    ? 'is declared non-secret but holds an encrypted value; clear it before changing the declaration'
                    : 'is declared secret but holds an unencrypted value; clear it before changing the declaration'
            );

            return;
        }

        // Re-typing a row that holds a value can make the value invalid for its own
        // type. Refused for the same reason: the synchroniser cannot know what the
        // operator meant, and guessing would corrupt the setting.
        if ($row->type !== $definition->type && $row->value !== null && ! $this->typeChangeIsSafe($row, $definition)) {
            $report->conflict(
                $reference,
                "holds a value that is not valid as [{$definition->type->value}]; correct or clear it before changing the declaration"
            );

            return;
        }

        $changes = [];

        foreach ([
            'type' => $definition->type,
            'is_secret' => $definition->isSecret,
            'is_public' => $definition->isPublic,
            'is_localized' => $definition->isLocalized,
        ] as $attribute => $declared) {
            if ($row->{$attribute} !== $declared) {
                $changes[$attribute] = $declared;
            }
        }

        if ($changes === []) {
            $report->unchanged($reference);

            return;
        }

        $row->forceFill($changes)->save();

        $report->updated($reference);
    }

    /**
     * Whether an existing value survives its declaration changing type.
     *
     * Checked rather than assumed: a stored `8` is a valid integer and a valid string,
     * while a stored `hello` is neither an integer nor a media id.
     */
    private function typeChangeIsSafe(Setting $row, SettingDefinition $definition): bool
    {
        try {
            $raw = $row->getRawValue();

            if ($raw === null) {
                return true;
            }

            Setting::castValue($raw, $definition->type);

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Report rows the registry no longer declares, and change nothing about them.
     *
     * A definition can vanish for reasons that have nothing to do with intent, and
     * the more valuable the setting the more likely it was configured rather than
     * left at its default. A configured secret is the case with no recovery at all:
     * a deleted credential cannot be reconstructed from anything the platform still
     * holds. So nothing here deletes, and the report carries the key alone — never
     * the value, and never the ciphertext.
     *
     * @param  array<string, Setting>  $rows
     */
    private function reportOrphans(array $rows, SynchronisationReport $report): void
    {
        $active = $this->registry->active();

        foreach ($rows as $reference => $row) {
            if (! isset($active[$reference])) {
                $report->orphaned($reference);
            }
        }
    }
}
