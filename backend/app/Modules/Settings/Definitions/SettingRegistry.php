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
     * Catalogues whose settings constrain one another.
     *
     * @var list<ConstrainsSettings>
     */
    private array $constraints = [];

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

        if ($catalogue instanceof ConstrainsSettings) {
            $this->constraints[] = $catalogue;
        }
    }

    /**
     * Every catalogue that declares constraints across its settings.
     *
     * @return list<ConstrainsSettings>
     */
    public function constraints(): array
    {
        return $this->constraints;
    }

    /**
     * Every registered definition, keyed by reference: groups in alphabetical order,
     * and within a group the order its catalogue declared them in.
     *
     * Sorting the whole reference alphabetically, as this once did, threw away the one
     * ordering anybody had actually thought about. A catalogue declares its settings in
     * the order an operator reads them — GeneralCatalogue builds itself from identity,
     * contact, urls, footer and operations, each a method with a reason written above
     * it — and the alphabet then interleaved them by the English spelling of their
     * keys. The site name landed seventeenth of eighteen, the maintenance message and
     * the administrator bypass sorted ahead of the maintenance mode they belong to, and
     * in a console reading Arabic the sequence carried no meaning at all, because the
     * labels on screen are not the strings being sorted.
     *
     * Groups stay alphabetical. The admin catalogue endpoint buckets this list by group
     * as it walks it, so a group's definitions must stay contiguous; ordering the
     * groups by registration instead would also change the shape of that payload, and
     * the one thing being fixed here is the order within a group.
     *
     * @return array<string, SettingDefinition>
     */
    public function all(): array
    {
        $definitions = $this->definitions;

        // Declaration order is insertion order, which is what this records before the
        // sort disturbs it. It is compared explicitly rather than leaned on as sort
        // stability, so the guarantee is in the comparison rather than in the engine.
        $declared = array_flip(array_keys($definitions));

        uksort($definitions, static function (string $first, string $second) use ($definitions, $declared): int {
            $byGroup = $definitions[$first]->group <=> $definitions[$second]->group;

            return $byGroup !== 0 ? $byGroup : $declared[$first] <=> $declared[$second];
        });

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
