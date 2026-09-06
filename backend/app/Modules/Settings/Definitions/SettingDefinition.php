<?php

declare(strict_types=1);

namespace App\Modules\Settings\Definitions;

use App\Modules\Settings\Enums\SettingType;
use InvalidArgumentException;

/**
 * The declaration of one setting: everything true about it that is not its value.
 *
 * This is the single source of what settings exist and what their rules are
 * (ADR 0018, revised 2026-09-06). Before it, the same facts were spread across a
 * seeder row, a FormRequest, `SettingType`'s conversion and — after ADR 0030 — a
 * language file, and nothing could answer *what settings exist* without reading
 * all four.
 *
 * A definition is immutable and carries no value. Values live in rows, are set by
 * operators, and are never overwritten by a definition changing (ADR 0018's
 * synchronisation rules).
 */
final readonly class SettingDefinition
{
    /**
     * @param  string  $group  the settings group, matching the column
     * @param  string  $key  the key within the group; `group.key` is the full reference
     * @param  SettingType  $type  authoritative for conversion in both directions
     * @param  mixed  $default  written only when a row is first created
     * @param  bool  $nullable  whether an operator may explicitly unset this
     * @param  array<int, string>  $rules  Laravel validation rules applied before a write
     * @param  bool  $isSecret  encrypted at rest, masked in responses, never logged
     * @param  bool  $isPublic  exposed through the unauthenticated settings endpoints
     * @param  bool  $isLocalized  carries a value per locale in `setting_translations`
     * @param  bool  $editable  whether the admin API may change it at all
     * @param  string|null  $permission  required beyond `settings.update`, where the
     *                                   value is sensitive enough to warrant its own
     * @param  array<int, string>  $dependsOn  keys that must hold a usable value before
     *                                         this one takes effect
     * @param  string|null  $deprecatedSince  set when a definition is retired but its
     *                                        row must be kept and reported as orphaned
     */
    public function __construct(
        public string $group,
        public string $key,
        public SettingType $type,
        public mixed $default = null,
        public bool $nullable = true,
        public array $rules = [],
        public bool $isSecret = false,
        public bool $isPublic = false,
        public bool $isLocalized = false,
        public bool $editable = true,
        public ?string $permission = null,
        public array $dependsOn = [],
        public ?string $deprecatedSince = null,
    ) {
        $this->assertShape();
    }

    /**
     * The full reference an operator and the API both use.
     */
    public function reference(): string
    {
        return $this->group.'.'.$this->key;
    }

    /**
     * The translation key for this setting's human label (ADR 0030).
     *
     * Derived rather than declared, so a label cannot be invented at a call site and
     * a definition cannot disagree with the catalogue about its own name.
     */
    public function labelKey(): string
    {
        return 'setting.'.$this->reference();
    }

    /**
     * The translation key for its help text.
     */
    public function helpKey(): string
    {
        return $this->labelKey().'.help';
    }

    /**
     * Whether this definition still describes a setting the platform wants.
     */
    public function isDeprecated(): bool
    {
        return $this->deprecatedSince !== null;
    }

    /**
     * The invariants a definition must satisfy to be registrable at all.
     *
     * These are the same rules the database enforces on rows (ADR 0018), checked here
     * so a contradictory definition fails when it is declared rather than when it is
     * synchronised — a stack trace pointing at the catalogue rather than at a command.
     */
    private function assertShape(): void
    {
        if (preg_match('/^[a-z][a-z0-9_]{0,49}$/', $this->group) !== 1) {
            throw new InvalidArgumentException(
                "Setting group [{$this->group}] must be a lowercase identifier of at most 50 characters."
            );
        }

        if (preg_match('/^[a-z][a-z0-9_]{0,99}$/', $this->key) !== 1) {
            throw new InvalidArgumentException(
                "Setting key [{$this->key}] must be a lowercase identifier of at most 100 characters."
            );
        }

        // A credential has no language, and a per-locale copy of a secret multiplies
        // the thing that has to be protected (ADR 0018).
        if ($this->isSecret && $this->isLocalized) {
            throw new InvalidArgumentException(
                "Setting [{$this->reference()}] cannot be both secret and localized."
            );
        }

        // Enforced by a database constraint as well; declared here so it is caught
        // before a synchronisation attempt rather than by the engine.
        if ($this->isSecret && $this->isPublic) {
            throw new InvalidArgumentException(
                "Setting [{$this->reference()}] cannot be both secret and public."
            );
        }

        // A localized value is text a reader sees, so it can only ever be a string.
        if ($this->isLocalized && $this->type !== SettingType::STRING) {
            throw new InvalidArgumentException(
                "Localized setting [{$this->reference()}] must be of type string, got [{$this->type->value}]."
            );
        }

        // A secret is provisioned unset and supplied by an operator; a default would
        // put credential material in the codebase (ADR 0018).
        if ($this->isSecret && $this->default !== null) {
            throw new InvalidArgumentException(
                "Secret setting [{$this->reference()}] must not declare a default value."
            );
        }

        if (! $this->nullable && $this->default === null) {
            throw new InvalidArgumentException(
                "Non-nullable setting [{$this->reference()}] must declare a default value."
            );
        }

        foreach ($this->dependsOn as $dependency) {
            if (! str_contains($dependency, '.')) {
                throw new InvalidArgumentException(
                    "Setting [{$this->reference()}] declares an invalid dependency; expected a 'group.key' reference."
                );
            }
        }
    }
}
