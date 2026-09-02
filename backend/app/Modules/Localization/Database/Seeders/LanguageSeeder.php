<?php

declare(strict_types=1);

namespace App\Modules\Localization\Database\Seeders;

use App\Modules\Core\Contracts\LocaleResolverInterface;
use App\Modules\Localization\Enums\LanguageDirection;
use App\Modules\Localization\Models\Language;
use Illuminate\Database\Seeder;

class LanguageSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $languages = [
            [
                'code' => 'en',
                'name' => 'English',
                'native_name' => 'English',
                'direction' => LanguageDirection::LTR,
                'is_active' => true,
                'is_default' => true,
                'sort_order' => 1,
            ],
            [
                'code' => 'ar',
                'name' => 'Arabic',
                'native_name' => 'العربية',
                'direction' => LanguageDirection::RTL,
                'is_active' => true,
                'is_default' => false,
                'sort_order' => 2,
            ],
        ];

        foreach ($languages as $langData) {
            Language::query()->updateOrCreate(
                ['code' => $langData['code']],
                $langData
            );
        }

        if (app()->bound(LocaleResolverInterface::class)) {
            app(LocaleResolverInterface::class)->clearCache();
        }
    }
}
