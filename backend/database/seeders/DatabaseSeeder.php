<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Localization\Database\Seeders\LanguageSeeder;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([
            LanguageSeeder::class,
        ]);
    }
}
