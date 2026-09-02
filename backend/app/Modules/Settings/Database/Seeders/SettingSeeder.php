<?php

declare(strict_types=1);

namespace App\Modules\Settings\Database\Seeders;

use App\Modules\Settings\Enums\SettingType;
use App\Modules\Settings\Models\Setting;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class SettingSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $testSecret = env('APP_TEST_SECRET') ?: Str::random(32);

        $settings = [
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

            // Security Group (Internal parameters and dynamic secret)
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
            [
                'group' => 'security',
                'key' => 'api_secret_key',
                'value' => $testSecret,
                'type' => SettingType::STRING,
                'is_secret' => true,
                'is_public' => false,
                'description' => 'Internal API encryption key (encrypted at rest).',
            ],
        ];

        foreach ($settings as $data) {
            $setting = Setting::query()->firstOrNew([
                'group' => $data['group'],
                'key' => $data['key'],
            ]);

            $setting->type = $data['type'];
            $setting->is_secret = $data['is_secret'];
            $setting->is_public = $data['is_public'];
            $setting->description = $data['description'];
            $setting->setRawValue($data['value']);
            $setting->save();
        }
    }
}
