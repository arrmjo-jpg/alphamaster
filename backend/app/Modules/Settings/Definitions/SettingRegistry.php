<?php

declare(strict_types=1);

namespace App\Modules\Settings\Definitions;

use App\Modules\Settings\Exceptions\UnknownSettingKeyException;
use InvalidArgumentException;

/**
 * The authoritative catalogue of setting definitions.
 *
 * One registry, populated at boot from catalogue classes. A module that owns
 * settings contributes a catalogue rather than seeding rows, which is what makes
 * this the single source ADR 0018 (revised) requires: rows are materialised from
 * here by the synchroniser, and nothing else declares a setting.
 *
 * Registration is explicit and static. Definitions are not discovered by scanning,
 * not read from the database, and not constructed from configuration — a catalogue
 * is a class a developer wrote, which is the difference between an extension point
 * and the plugin system ADR 0033 rules out.
 */
class SettingRegistry
{
    /**
     * Definitions by `group.key`.
     *
     * @var array<string, SettingDefinition>
     */
    private array $definitions = [];

    /**
     * Register one definition.
     *
     * A duplicate reference is a programming error rather than a merge: two
     * catalogues disagreeing about the same setting has no correct resolution, and
     * silently taking the last one would make the winner depend on boot order.
     */
    public function register(SettingDefinition $definition): void
    {
        $reference = $definition->reference();

        if (isset($this->definitions[$reference])) {
            throw new InvalidArgumentException(
                "Setting [{$reference}] is already registered; a setting is declared exactly once."
            );
        }

        $this->definitions[$reference] = $definition;
    }

    /**
     * Register every definition a catalogue declares.
     */
    public function registerCatalogue(SettingCatalogue $catalogue): void
    {
        foreach ($catalogue->definitions() as $definition) {
            $this->register($definition);
        }
    }

    /**
     * Every registered definition, keyed by reference and ordered for stable output.
     *
     * @return array<string, SettingDefinition>
     */
    public function all(): array
    {
        $definitions = $this->definitions;
        ksort($definitions);

        return $definitions;
    }

    /**
     * The definitions belonging to one group, keyed by their key within it.
     *
     * @return array<string, SettingDefinition>
     */
    public function forGroup(string $group): array
    {
        $result = [];

        foreach ($this->all() as $definition) {
            if ($definition->group === $group) {
                $result[$definition->key] = $definition;
            }
        }

        return $result;
    }

    /**
     * Every group that has at least one definition, in order.
     *
     * @return array<int, string>
     */
    public function groups(): array
    {
        $groups = [];

        foreach ($this->all() as $definition) {
            $groups[$definition->group] = true;
        }

        return array_keys($groups);
    }

    public function has(string $reference): bool
    {
        return isset($this->definitions[$reference]);
    }

    /**
     * The definition for a reference.
     *
     * @throws UnknownSettingKeyException when nothing declares it — which is also the
     *                                    answer the admin API gives, since a setting
     *                                    that is not declared cannot be created
     *                                    through the API (ADR 0018).
     */
    public function get(string $reference): SettingDefinition
    {
        if (! isset($this->definitions[$reference])) {
            [$group, $key] = $this->split($reference);

            throw new UnknownSettingKeyException($group, $key);
        }

        return $this->definitions[$reference];
    }

    /**
     * The definition for a group and key.
     */
    public function find(string $group, string $key): SettingDefinition
    {
        return $this->get($group.'.'.$key);
    }

    /**
     * Definitions that are still current, i.e. not retired.
     *
     * @return array<string, SettingDefinition>
     */
    public function active(): array
    {
        return array_filter($this->all(), static fn (SettingDefinition $d): bool => ! $d->isDeprecated());
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function split(string $reference): array
    {
        $parts = explode('.', $reference, 2);

        if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
            throw new InvalidArgumentException(
                "Setting reference must be in the format 'group.key', received [{$reference}]."
            );
        }

        return [$parts[0], $parts[1]];
    }
}
