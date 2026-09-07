<?php

declare(strict_types=1);

namespace App\Modules\Integration\Backup;

use App\Modules\Core\Backup\RestoreReport;
use App\Modules\Core\Contracts\ConfigurationPortabilityContract;
use App\Modules\Integration\Models\IntegrationProvider;
use Illuminate\Support\Facades\DB;

/**
 * Provider configuration — which vendors are wired up and how — in and out of a
 * portable export (ADR 0039).
 *
 * **Credentials are not part of it, in any form.** ADR 0039 lists
 * `integration_providers.credentials` among the three stores encrypted with APP_KEY, and
 * lists provider *configuration metadata* among what an export carries: capability,
 * driver, label, settings, the active and default flags, priority. The credential column
 * is simply not read here, so there is no path that could carry it and none that could
 * be widened by accident.
 *
 * The practical consequence is the one ADR 0039 states plainly: a restore into a new
 * environment means re-supplying every provider's credentials. That is a checklist, and
 * it is a better outcome than an export file that is worth stealing.
 */
class ProviderPortability implements ConfigurationPortabilityContract
{
    public function section(): string
    {
        return 'integration';
    }

    /**
     * Last. Nothing else depends on provider metadata, and it depends on nothing else.
     */
    public function order(): int
    {
        return 30;
    }

    /**
     * @return array{data: array<string, mixed>, omitted_secrets: array<int, string>}
     */
    public function export(bool $includeSecrets): array
    {
        $providers = [];
        $omitted = [];

        foreach (IntegrationProvider::query()->orderBy('capability')->orderBy('driver')->get() as $provider) {
            $providers[] = [
                'capability' => $provider->capability,
                'driver' => $provider->driver,
                'label' => $provider->label,
                'settings' => $provider->settings,
                'is_active' => $provider->is_active,
                'is_default' => $provider->is_default,
                'priority' => $provider->priority,
            ];

            // Named whether or not the operator asked for secrets, because this export
            // never carries a provider credential either way. Reporting it only in the
            // omit-secrets case would imply the other case included them.
            if ($provider->getRawOriginal('credentials') !== null) {
                $omitted[] = $provider->capability->value.'.'.$provider->driver.'.credentials';
            }
        }

        return ['data' => ['providers' => $providers], 'omitted_secrets' => $omitted];
    }

    /**
     * @param  array<string, mixed>  $section
     */
    public function restore(array $section, bool $mayWriteEncrypted): RestoreReport
    {
        $restored = 0;
        $skipped = [];

        DB::transaction(function () use ($section, &$restored, &$skipped): void {
            /** @var array<int, array<string, mixed>> $rows */
            $rows = is_array($section['providers'] ?? null) ? $section['providers'] : [];

            foreach ($rows as $row) {
                $capability = $row['capability'] ?? null;
                $driver = $row['driver'] ?? null;

                if (! is_string($capability) || ! is_string($driver)) {
                    $skipped[] = ['key' => 'provider', 'reason' => 'malformed'];

                    continue;
                }

                // Matched on the pair that identifies a provider, and updated in place.
                // A credential already configured here is untouched: the export carries
                // none, so a restore has nothing to overwrite it with, and clearing it
                // would break a working integration to apply a file that never mentioned
                // it.
                IntegrationProvider::query()->updateOrCreate(
                    ['capability' => $capability, 'driver' => $driver],
                    [
                        'label' => (string) ($row['label'] ?? $driver),
                        'settings' => is_array($row['settings'] ?? null) ? $row['settings'] : [],
                        'is_active' => (bool) ($row['is_active'] ?? false),
                        'is_default' => (bool) ($row['is_default'] ?? false),
                        'priority' => (int) ($row['priority'] ?? 0),
                    ],
                );

                $restored++;
            }
        });

        return RestoreReport::of($this->section(), $restored, $skipped);
    }
}
