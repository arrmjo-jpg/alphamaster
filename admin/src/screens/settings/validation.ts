import type { SettingDefinition } from './api';

/**
 * Runtime validation, read from the rules the platform publishes.
 *
 * `rules` is in the catalogue because the API enforces them: a client that generates
 * a form and does not know them submits values the API refuses, and an operator reads
 * that as the platform being broken. So the same declarations drive the form.
 *
 * Two rules govern everything here.
 *
 * **Never stricter than the server.** An unrecognised rule is ignored rather than
 * guessed at. Being stricter would block a value the platform would have accepted,
 * which is worse than being lenient — the API is still the boundary, and a value this
 * misses is refused there with the platform's own message.
 *
 * **Never a second dialect.** These are Laravel rule strings, deliberately, and they
 * are interpreted rather than translated into some neutral schema. Translating them
 * would create two descriptions of one constraint, and they would drift in exactly
 * the direction that makes the client more permissive than the server.
 */

export interface ValidationMessage {
    /** A translation key, so the message is the reader's language and not English. */
    key: string;
    values?: Record<string, string | number>;
}

interface Rule {
    name: string;
    args: string[];
}

function parse(rules: readonly string[]): Rule[] {
    return rules.map((rule) => {
        const [name = '', rest = ''] = rule.split(':', 2);

        return { name: name.trim(), args: rest === '' ? [] : rest.split(',') };
    });
}

function isBlank(value: unknown): boolean {
    return value === null || value === undefined || value === '';
}

/**
 * The first thing wrong with a value, or null.
 *
 * First rather than all: a field shows one message, and the operator fixes one thing
 * at a time. The server reports the rest if more than one is wrong.
 */
export function validate(definition: SettingDefinition, value: unknown): ValidationMessage | null {
    const rules = parse(definition.rules);

    if (isBlank(value)) {
        // `nullable` is the definition's own word for whether empty is a value.
        return definition.nullable ? null : { key: 'settings.validation.required' };
    }

    for (const rule of rules) {
        const message = check(rule, value, definition);

        if (message !== null) {
            return message;
        }
    }

    return null;
}

function check(
    rule: Rule,
    value: unknown,
    definition: SettingDefinition,
): ValidationMessage | null {
    const text = typeof value === 'string' ? value : String(value);
    const numeric = typeof value === 'number' ? value : Number(text);
    const bound = Number(rule.args[0]);
    const countsCharacters =
        definition.type === 'string' || definition.type === 'url' || definition.type === 'email';

    switch (rule.name) {
        case 'integer':
            return Number.isInteger(numeric) ? null : { key: 'settings.validation.integer' };

        case 'numeric':
            return Number.isFinite(numeric) ? null : { key: 'settings.validation.numeric' };

        case 'boolean':
            return typeof value === 'boolean' ? null : { key: 'settings.validation.boolean' };

        case 'email':
            // Deliberately loose. The server owns what an address is; this catches a
            // typed mistake, not a malformed domain.
            return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(text)
                ? null
                : { key: 'settings.validation.email' };

        case 'url':
            return URL.canParse(text) ? null : { key: 'settings.validation.url' };

        case 'ulid':
            return /^[0-9A-HJKMNP-TV-Z]{26}$/i.test(text)
                ? null
                : { key: 'settings.validation.ulid' };

        case 'min':
            if (Number.isNaN(bound)) {
                return null;
            }

            // `min` counts characters for a string and compares magnitude for a
            // number — the same split Laravel makes, and getting it backwards would
            // reject a valid short number or a valid small string.
            return countsCharacters
                ? text.length >= bound
                    ? null
                    : { key: 'settings.validation.minLength', values: { count: bound } }
                : numeric >= bound
                  ? null
                  : { key: 'settings.validation.min', values: { count: bound } };

        case 'max':
            if (Number.isNaN(bound)) {
                return null;
            }

            return countsCharacters
                ? text.length <= bound
                    ? null
                    : { key: 'settings.validation.maxLength', values: { count: bound } }
                : numeric <= bound
                  ? null
                  : { key: 'settings.validation.max', values: { count: bound } };

        case 'in':
            return rule.args.includes(text)
                ? null
                : { key: 'settings.validation.in', values: { values: rule.args.join(', ') } };

        default:
            // Unrecognised, so unenforced. The API is still the boundary.
            return null;
    }
}

/** The permitted values a rule names, so a control can offer them instead of free text. */
export function allowedValues(definition: SettingDefinition): string[] | null {
    const rule = parse(definition.rules).find((candidate) => candidate.name === 'in');

    return rule === undefined || rule.args.length === 0 ? null : rule.args;
}
