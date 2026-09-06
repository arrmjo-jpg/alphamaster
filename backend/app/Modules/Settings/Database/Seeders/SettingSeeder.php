<?php

declare(strict_types=1);

namespace App\Modules\Settings\Database\Seeders;

use App\Modules\Settings\Definitions\SettingSynchronizer;
use Illuminate\Database\Seeder;

/**
 * Provision the settings catalogue from the definition registry.
 *
 * This seeder declares nothing. It used to hold the definition of every setting —
 * type, flags, default and description — which made it a second source of truth
 * beside the registry ADR 0018 (revised) makes authoritative. Now it delegates, so
 * there is one place a setting is declared and one mechanism that materialises it.
 *
 * It remains a seeder because a fresh database still needs its settings, and
 * because `db:seed` is where an operator and the test suite both already look. The
 * synchroniser it calls is idempotent and never overwrites a configured value, so
 * re-running this is safe for exactly the reasons it was safe before.
 */
class SettingSeeder extends Seeder
{
    public function run(): void
    {
        app(SettingSynchronizer::class)->synchronise();
    }
}
