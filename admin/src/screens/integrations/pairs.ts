/**
 * A map, as an ordered list of rows.
 *
 * Both fields the platform models as a keyed map — a provider's non-secret settings
 * and its credentials — have to be edited as rows, because a map has no order and a
 * form must. Kept apart from the component that renders them so the conversion can be
 * tested, and reasoned about, without rendering anything.
 */
export interface Pair {
    /** Stable across re-renders, so typing in one row does not re-key the list. */
    uid: string;
    name: string;
    value: string;
}

let counter = 0;

export function newPair(name = '', value = ''): Pair {
    counter += 1;

    return { uid: `pair-${counter}`, name, value };
}

/** The stored map, as rows. A null value is an unset setting and edits as empty. */
export function toPairs(map: Record<string, string | null> | null | undefined): Pair[] {
    return Object.entries(map ?? {}).map(([name, value]) => newPair(name, value ?? ''));
}

/**
 * The rows, as the API takes them.
 *
 * Rows with no name are dropped rather than sent under an empty key, and a later row
 * with the same name wins — which is what the operator sees, since only one of two
 * identically named rows can survive a round trip through a map.
 */
export function fromPairs(pairs: readonly Pair[]): Record<string, string> {
    const map: Record<string, string> = {};

    for (const pair of pairs) {
        const name = pair.name.trim();

        if (name !== '') {
            map[name] = pair.value;
        }
    }

    return map;
}
