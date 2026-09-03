<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Localization\Database\Seeders\LanguageSeeder;
use App\Modules\Settings\Database\Seeders\SettingSeeder;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     *
     * WithoutModelEvents is deliberately NOT used here. It silently suppresses the
     * models' own guards and cache invalidation for every nested seeder, which turned
     * `db:seed` into a path that could bypass application invariants. Data invariants
     * are enforced by database constraints, and each seeder invalidates its own caches
     * explicitly, so seeding runs with model events intact.
     */
    public function run(): void
    {
        $this->call([
            LanguageSeeder::class,
            SettingSeeder::class,
        ]);
    }
}
