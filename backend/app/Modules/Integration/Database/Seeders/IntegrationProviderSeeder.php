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
            [
                // Inactive and credential-less, like Twilio — but for a second reason
                // as well. There is no log-style captcha driver and there will not be
                // one: a captcha that answers "pass" without asking a vendor is not a
                // degraded captcha, it is the absence of one wearing its name. A fresh
                // installation therefore has no working captcha provider, which is
                // correct, because it also has the capability switched off.
                'capability' => IntegrationCapability::CAPTCHA,
                'driver' => 'recaptcha',
                'label' => 'Google reCAPTCHA',
                // Empty rather than absent, so an operator sees the field exists.
                // A null minimum means the vendor's own verdict stands, which is what
                // v2 needs; a v3 deployment sets it.
                'settings' => ['minimum_score' => null],
                'is_active' => false,
                'is_default' => true,
                'priority' => 0,
            ],
            [
                // Inactive and credential-less, for the reasons Twilio and reCAPTCHA
                // are — and for a third that belongs to this capability. There is no
                // log-style AI driver and there will not be one: a generator that
                // answers without asking a vendor produces plausible text with no
                // relationship to the request, which is worse than no answer when the
                // answer is a translation somebody may accept.
                //
                // `base_url` is a setting rather than a constant because every
                // OpenAI-compatible gateway — Azure, a proxy, a corporate egress —
                // speaks this protocol at a different address. Empty means the
                // vendor's own.
                'capability' => IntegrationCapability::AI,
                'driver' => 'openai',
                'label' => 'OpenAI',
                'settings' => ['base_url' => ''],
                'is_active' => false,
                'is_default' => true,
                'priority' => 0,
            ],
            [
                // Inactive and credential-less, like every other vendor row. The
                // credential this one takes is unusual and worth naming: FCM v1
                // authenticates with a Google service account — a JSON document
                // containing a private key — rather than an API key, so the Admin's
                // secret field has to accept a paste rather than a line (ADR 0045 §2).
                'capability' => IntegrationCapability::PUSH,
                'driver' => 'fcm',
                'label' => 'Firebase Cloud Messaging',
                'settings' => null,
                'is_active' => false,
                'is_default' => true,
                'priority' => 0,
            ],
            [
                'capability' => IntegrationCapability::AI,
                'driver' => 'anthropic',
                'label' => 'Anthropic',
                'settings' => ['base_url' => ''],
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
