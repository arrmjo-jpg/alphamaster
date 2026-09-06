<?php

declare(strict_types=1);

namespace App\Modules\Core\Backup;

use App\Modules\Core\Contracts\ConfigurationPortabilityContract;
use RuntimeException;

/**
 * Which modules contribute to a configuration export, and in what order (ADR 0039).
 *
 * Registration order is not the restore order. A module registers whenever its provider
 * boots, which depends on the provider list; the restore order is a dependency order and
 * has to be stated by the contributors themselves, so it is read from `order()` and
 * sorted here rather than left to whichever provider happened to boot first.
 */
class ConfigurationPortability
{
    /**
     * @var array<string, ConfigurationPortabilityContract>
     */
    private array $contributors = [];

    public function register(ConfigurationPortabilityContract $contributor): void
    {
        $section = $contributor->section();

        if (isset($this->contributors[$section])) {
            throw new RuntimeException("A configuration contributor is already registered for section [{$section}].");
        }

        $this->contributors[$section] = $contributor;
    }

    /**
     * Contributors in dependency order: languages before the values attached to them,
     * definitions before the values validated against them.
     *
     * @return array<int, ConfigurationPortabilityContract>
     */
    public function ordered(): array
    {
        $contributors = array_values($this->contributors);

        usort(
            $contributors,
            static fn (ConfigurationPortabilityContract $a, ConfigurationPortabilityContract $b): int => $a->order() <=> $b->order(),
        );

        return $contributors;
    }

    /**
     * @return array<int, string>
     */
    public function sections(): array
    {
        return array_keys($this->contributors);
    }
}
