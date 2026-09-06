<?php

declare(strict_types=1);

namespace App\Modules\Settings\Definitions;

/**
 * A group of setting definitions declared together by whoever owns them.
 *
 * The unit of ownership. Settings declares the platform-level catalogues; a module
 * that acquires settings of its own contributes a catalogue rather than reaching
 * into Settings, which keeps the registry a single source without making it a
 * single file.
 */
interface SettingCatalogue
{
    /**
     * @return array<int, SettingDefinition>
     */
    public function definitions(): array;
}
