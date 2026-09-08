import { useEffect, useState } from 'react';

/**
 * Whether a media query currently matches.
 *
 * Used where a responsive change is a change of *structure* rather than of styling —
 * a table becoming a list of records, say. Doing that with `hidden md:block` leaves
 * both versions in the document, so every row exists twice: a screen reader reads it
 * twice, `getByText` finds two of it, and the duplicate is invisible to the person
 * writing the CSS. Rendering one or the other keeps the accessibility tree honest.
 *
 * Only for structure. Anything that is genuinely just styling belongs in CSS, where
 * it costs no JavaScript and needs no listener.
 *
 * `matchMedia` is absent in some test environments, so its absence resolves to false
 * rather than throwing — which renders the narrow layout, the one that works
 * everywhere.
 */
export function useMediaQuery(query: string): boolean {
    const [matches, setMatches] = useState(() => window.matchMedia?.(query).matches ?? false);

    useEffect(() => {
        const list = window.matchMedia?.(query);

        if (list === undefined) {
            return;
        }

        setMatches(list.matches);

        const listener = (event: MediaQueryListEvent) => setMatches(event.matches);
        list.addEventListener('change', listener);

        return () => list.removeEventListener('change', listener);
    }, [query]);

    return matches;
}
