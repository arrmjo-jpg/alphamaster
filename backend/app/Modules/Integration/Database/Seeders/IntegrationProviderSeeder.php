<?php

declare(strict_types=1);

namespace App\Modules\Integration\Database\Seeders;

use App\Modules\Integration\Enums\IntegrationCapability;
use App\Modules\Integration\Models\IntegrationProvider;
use Illuminate\Database\Seeder;

class IntegrationProviderSeeder extends Seeder
{
    /**
     * Providers shipped with the platform.
     *
     * The log driver is the default because it is the only one that works without an
     * operator supplying credentials, so a fresh installation can send without
     * silently failing. Twilio is provisioned but inactive and credential-less: an
     * operator activates it once they have supplied keys, exactly as a secret setting
     * is provisioned unset (ADR 0018).
     *
     * @return array<int, array<string, mixed>>
     */
    private function definitions(): array
    {
        return [
            [
                'capability' => IntegrationCapability::SMS,
                'driver' => 'log',
                'label' => 'Log (writes messages to the application log)',
                'settings' => null,
                'is_active' => true,
                'is_default' => true,
                'priority' => 0,
            ],
            [
                'capability' => IntegrationCapability::SMS,
                'driver' => 'twilio',
                'label' => 'Twilio',
                'settings' => ['from' => ''],
                'is_active' => false,
                'is_default' => false,
                'priority' => 10,
            ],
        ];
    }

    /**
     * Idempotent and non-destructive, like the settings seeder: it inserts what is
     * missing and never overwrites an operator's configuration or credentials.
     */
    public function run(): void
    {
        $existing = IntegrationProvider::query()
            ->get(['capability', 'driver'])
            ->map(fn (IntegrationProvider $p): string => $p->capability->value.'.'.$p->driver)
            ->all();

        foreach ($this->definitions() as $definition) {
            $key = $definition['capability']->value.'.'.$definition['driver'];

            if (in_array($key, $existing, true)) {
                continue;
            }

            IntegrationProvider::query()->create($definition);
        }
    }
}
