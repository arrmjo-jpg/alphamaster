import type { SettingDefinition, SettingRow } from './api';
import type { SettingStatus } from './state';
import { validate, type ValidationMessage } from './validation';

/**
 * What an operator has changed but not yet saved.
 *
 * Kept apart from the values the platform returned, so "modified" is a fact rather
 * than a guess — which is what lets a changed row carry the `pending` rail instead of
 * borrowing `warning`, what makes discarding an edit possible without refetching, and
 * what makes a review showing old beside new possible at all.
 */
export type Draft = Record<string, unknown>;

/**
 * The catalogue and the values disagree about what "key" means, and this is where
 * that is reconciled.
 *
 * A definition's `key` is the fully qualified reference — `general.contact_person` —
 * while its `name` is the bare key, and a value row carries the bare one. Matching
 * the wrong pair is silent: every field renders empty because no row is found, and
 * every write is refused because the qualified form is not a valid setting key. Both
 * happened, against the running platform, before this was named.
 */
export function settingKey(definition: SettingDefinition): string {
    return definition.name;
}

/** The form `depends_on` is declared in: `group.key`, never the bare key. */
function reference(row: SettingRow): string {
    return `${row.group}.${row.key}`;
}

export interface FieldState {
    definition: SettingDefinition;
    row: SettingRow | undefined;
    /** The value on screen: the draft's if edited, the platform's otherwise. */
    value: unknown;
    saved: unknown;
    changed: boolean;
    status: SettingStatus;
    /** What is wrong with the staged value, from the platform's own rules. */
    invalid: ValidationMessage | null;
    /** The platform's refusal for this key, when it has one. */
    rejected: string | null;
    /** False when the account lacks the permission this setting names. */
    editable: boolean;
    /** Why it is not editable, when it is not. */
    readOnlyReason: 'permission' | 'declared' | null;
    /** References the setting must have configured first, and does not. */
    unmet: string[];
    /** Withdrawn from the catalogue: still stored, no longer meant to be used. */
    deprecated: boolean;
    secret: boolean;
    localized: boolean;
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

export interface FieldStateInput {
    definitions: SettingDefinition[];
    rows: SettingRow[];
    draft: Draft;
    permissions: readonly string[];
    /** Keys the platform refused on the last save, by bare key. */
    rejections?: Record<string, string>;
    /** True while a save is in flight, which makes every changed row `pending`. */
    saving?: boolean;
    /** True when the last save was refused because the version had moved. */
    conflicted?: boolean;
}

export function fieldStates({
    definitions,
    rows,
    draft,
    permissions,
    rejections = {},
    saving = false,
    conflicted = false,
}: FieldStateInput): FieldState[] {
    return definitions.map((definition) => {
        const key = settingKey(definition);
        const row = rows.find((candidate) => candidate.key === key);
        const saved = row?.value;
        const edited = Object.hasOwn(draft, key);
        const value = edited ? draft[key] : saved;
        const changed = edited && !Object.is(draft[key], saved);

        // Two separate refusals, and both are the platform's: a setting declared
        // uneditable is nobody's to change, and one naming a permission is changeable
        // only by an account holding it. The API enforces both per key; this only
        // avoids offering what would be refused, and says which of the two it is so
        // the field reads as deliberate rather than broken.
        const permitted =
            definition.permission === null || permissions.includes(definition.permission);
        const editable = definition.editable && permitted;

        const rejected = rejections[key] ?? null;

        return {
            definition,
            row,
            value,
            saved,
            changed,
            status: statusOf({ changed, saving, conflicted, rejected, definition, saved }),
            invalid: changed ? validate(definition, value) : null,
            rejected,
            editable,
            readOnlyReason: editable ? null : definition.editable ? 'permission' : 'declared',
            unmet: unmetDependencies(definition, rows),
            deprecated: definition.deprecated,
            secret: definition.is_secret,
            localized: definition.is_localized,
        };
    });
}

function statusOf({
    changed,
    saving,
    conflicted,
    rejected,
    definition,
    saved,
}: {
    changed: boolean;
    saving: boolean;
    conflicted: boolean;
    rejected: string | null;
    definition: SettingDefinition;
    saved: unknown;
}): SettingStatus {
    // Order matters: a refusal is the most recent thing the platform said, and a
    // conflict is the reason a refusal happened to everything at once.
    if (rejected !== null) {
        return 'failed';
    }

    if (conflicted && changed) {
        return 'conflict';
    }

    if (saving && changed) {
        return 'pending';
    }

    if (changed) {
        return 'modified';
    }

    // A secret holding a value nobody has exercised. The platform verifies a
    // credential when it is rotated, so "set" and "known to work" are different
    // facts, and an interface showing only the first would be reassuring about
    // something it had not checked.
    if (definition.is_secret && saved !== null && saved !== undefined) {
        return 'unverified';
    }

    return 'unchanged';
}

/** Only what actually differs from what the platform holds. */
export function changedValues(fields: FieldState[]): Draft {
    return Object.fromEntries(
        fields
            .filter((field) => field.changed)
            .map((field) => [settingKey(field.definition), field.value]),
    );
}

/** The staged edits that cannot be sent, so a save is never attempted with them. */
export function invalidFields(fields: FieldState[]): FieldState[] {
    return fields.filter((field) => field.changed && field.invalid !== null);
}
