import type { SettingDefinition, SettingRow } from './api';

/**
 * What an operator has changed but not yet saved.
 *
 * Kept apart from the values the platform returned, so "modified" is a fact rather
 * than a guess — which is what lets a changed row carry the `pending` state instead
 * of borrowing `warning`, and what makes discarding an edit possible without
 * refetching.
 */
export type Draft = Record<string, unknown>;

export interface FieldState {
    definition: SettingDefinition;
    row: SettingRow | undefined;
    /** The value on screen: the draft's if edited, the platform's otherwise. */
    value: unknown;
    saved: unknown;
    changed: boolean;
    /** False when the account lacks the permission this setting names. */
    editable: boolean;
    /** Names the setting must have configured first, and does not. */
    unmet: string[];
}

/**
 * The catalogue and the values disagree about what "key" means, and this is where
 * that is reconciled.
 *
 * A definition's `key` is the fully qualified reference — `general.contact_person` —
 * while its `name` is the bare key, and a value row carries the bare one. Matching
 * the wrong pair is silent: every field renders empty because no row is found, and
 * every write is refused because the qualified form is not a valid setting key.
 * Both happened, against the running platform, before this was named.
 */
export function settingKey(definition: SettingDefinition): string {
    return definition.name;
}

/** The form `depends_on` is declared in: `group.key`, never the bare key. */
function reference(row: SettingRow): string {
    return `${row.group}.${row.key}`;
}

/**
 * `depends_on` is published so an interface can say what is missing rather than
 * letting an operator switch on something that silently does nothing. A dependency
 * counts as met when it holds a value that is neither null nor empty.
 */
function unmetDependencies(definition: SettingDefinition, rows: SettingRow[]): string[] {
    return definition.depends_on.filter((dependency) => {
        const row = rows.find((candidate) => reference(candidate) === dependency);
        const value = row?.value;

        return value === undefined || value === null || value === '';
    });
}

export function fieldStates(
    definitions: SettingDefinition[],
    rows: SettingRow[],
    draft: Draft,
    permissions: readonly string[],
): FieldState[] {
    return definitions.map((definition) => {
        const key = settingKey(definition);
        const row = rows.find((candidate) => candidate.key === key);
        const saved = row?.value;
        const edited = Object.hasOwn(draft, key);

        return {
            definition,
            row,
            value: edited ? draft[key] : saved,
            saved,
            changed: edited && !Object.is(draft[key], saved),
            // Two separate refusals, and both are the platform's: a setting declared
            // uneditable is nobody's to change, and one naming a permission is
            // changeable only by an account holding it. The API enforces both per key;
            // this only avoids offering what would be refused.
            editable:
                definition.editable &&
                (definition.permission === null || permissions.includes(definition.permission)),
            unmet: unmetDependencies(definition, rows),
        };
    });
}

/** Only what actually differs from what the platform holds. */
export function changedValues(fields: FieldState[]): Draft {
    return Object.fromEntries(
        fields
            .filter((field) => field.changed)
            .map((field) => [settingKey(field.definition), field.value]),
    );
}
