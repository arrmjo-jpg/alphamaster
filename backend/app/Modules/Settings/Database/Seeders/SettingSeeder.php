<?php

declare(strict_types=1);

namespace App\Modules\Settings\Database\Seeders;

use App\Modules\Settings\Contracts\SettingServiceInterface;
use App\Modules\Settings\Enums\SettingType;
use App\Modules\Settings\Models\Setting;
use Illuminate\Database\Seeder;

class SettingSeeder extends Seeder
{
    /**
     * Provision the baseline settings catalogue.
     *
     * This seeder is idempotent and non-destructive: it only inserts settings that do
     * not exist yet. An already provisioned setting is left exactly as it is, so
     * re-running the seeder never reverts an operator's customisations and never
     * rotates a secret.
     */
    public function run(): void
    {
        $existing = Setting::query()
            ->get(['group', 'key'])
            ->map(fn (Setting $s): string => $s->group.'.'.$s->key)
            ->all();

        $created = 0;

        foreach ($this->definitions() as $data) {
            if (in_array($data['group'].'.'.$data['key'], $existing, true)) {
                continue;
            }

            $setting = new Setting([
                'group' => $data['group'],
                'key' => $data['key'],
                'type' => $data['type'],
                'is_secret' => $data['is_secret'],
                'is_public' => $data['is_public'],
                'description' => $data['description'],
            ]);

            $setting->setRawValue($data['value']);
            $setting->save();

            $created++;
        }

        if ($created > 0) {
            $this->clearSettingsCache();
        }
    }

    /**
     * The baseline settings catalogue.
     *
     * @return array<int, array{group: string, key: string, value: string|null, type: SettingType, is_secret: bool, is_public: bool, description: string}>
     */
    private function definitions(): array
    {
        return [
            // General Group (Public)
            [
                'group' => 'general',
                'key' => 'site_name',
                'value' => 'AlphaMaster Enterprise',
                'type' => SettingType::STRING,
                'is_secret' => false,
                'is_public' => true,
                'description' => 'Public platform brand name.',
            ],
            [
                'group' => 'general',
                'key' => 'site_description',
                'value' => 'Modern Modular SaaS & Foundation',
                'type' => SettingType::STRING,
                'is_secret' => false,
                'is_public' => true,
                'description' => 'Public platform description for meta tags.',
            ],
            [
                'group' => 'general',
                'key' => 'maintenance_mode',
                'value' => 'false',
                'type' => SettingType::BOOLEAN,
                'is_secret' => false,
                'is_public' => true,
                'description' => 'Toggle public maintenance mode.',
            ],

            // Localization Group (Public)
            [
                'group' => 'localization',
                'key' => 'timezone',
                'value' => 'UTC',
                'type' => SettingType::STRING,
                'is_secret' => false,
                'is_public' => true,
                'description' => 'Default system timezone.',
            ],
            [
                'group' => 'localization',
                'key' => 'date_format',
                'value' => 'Y-m-d',
                'type' => SettingType::STRING,
                'is_secret' => false,
                'is_public' => true,
                'description' => 'Standard date formatting.',
            ],

            // Auth Group (Public registration flag, internal session lifetime)
            [
                'group' => 'auth',
                'key' => 'registration_enabled',
                'value' => 'true',
                'type' => SettingType::BOOLEAN,
                'is_secret' => false,
                'is_public' => true,
                'description' => 'Allow new users to self-register.',
            ],
            [
                'group' => 'auth',
                'key' => 'password_min_length',
                'value' => '8',
                'type' => SettingType::INTEGER,
                'is_secret' => false,
                'is_public' => true,
                'description' => 'Minimum password length constraint.',
            ],
            [
                'group' => 'auth',
                'key' => 'session_lifetime',
                'value' => '120',
                'type' => SettingType::INTEGER,
                'is_secret' => false,
                'is_public' => false,
                'description' => 'Session lifetime in minutes.',
            ],

            // Security Group (Internal parameters and operator provisioned secret)
            [
                'group' => 'security',
                'key' => 'max_login_attempts',
                'value' => '5',
                'type' => SettingType::INTEGER,
                'is_secret' => false,
                'is_public' => false,
                'description' => 'Max failed attempts before throttling.',
            ],
            [
                'group' => 'security',
                'key' => 'decay_minutes',
                'value' => '1',
                'type' => SettingType::INTEGER,
                'is_secret' => false,
                'is_public' => false,
                'description' => 'Lockout duration in minutes.',
            ],
            // Rate Limit Group (central API limiter; the auth throttles above stay
            // authoritative and are always tighter than these ceilings)
            [
                'group' => 'rate_limit',
                'key' => 'public_read_per_minute',
                'value' => '60',
                'type' => SettingType::INTEGER,
                'is_secret' => false,
                'is_public' => false,
                'description' => 'Requests per minute per IP for anonymous read endpoints.',
            ],
            [
                'group' => 'rate_limit',
                'key' => 'read_per_minute',
                'value' => '120',
                'type' => SettingType::INTEGER,
                'is_secret' => false,
                'is_public' => false,
                'description' => 'Requests per minute per authenticated user for read endpoints.',
            ],
            [
                'group' => 'rate_limit',
                'key' => 'write_per_minute',
                'value' => '30',
                'type' => SettingType::INTEGER,
                'is_secret' => false,
                'is_public' => false,
                'description' => 'Requests per minute per authenticated user for write endpoints.',
            ],
            [
                'group' => 'rate_limit',
                'key' => 'upload_per_hour',
                'value' => '20',
                'type' => SettingType::INTEGER,
                'is_secret' => false,
                'is_public' => false,
                'description' => 'Uploads per hour per authenticated user.',
            ],
            [
                'group' => 'rate_limit',
                'key' => 'authenticated_ip_multiplier',
                'value' => '4',
                'type' => SettingType::INTEGER,
                'is_secret' => false,
                'is_public' => false,
                'description' => 'How much looser the per-IP ceiling is than the per-user one, so shared NAT does not throttle unrelated users.',
            ],
            [
                'group' => 'security',
                'key' => 'api_secret_key',
                // Deliberately unset: a secret is provisioned by an operator through the
                // admin API, never generated by a seeder that may run in any environment.
                'value' => null,
                'type' => SettingType::STRING,
                'is_secret' => true,
                'is_public' => false,
                'description' => 'Internal API encryption key (encrypted at rest). Unset until provisioned by an administrator.',
            ],
        ];
    }

    /**
     * Drop cached settings so a freshly provisioned catalogue is visible immediately.
     *
     * Done explicitly rather than through model events, which seeders may run without.
     */
    private function clearSettingsCache(): void
    {
        if (app()->bound(SettingServiceInterface::class)) {
            app(SettingServiceInterface::class)->clearCache();
        }
    }
}
