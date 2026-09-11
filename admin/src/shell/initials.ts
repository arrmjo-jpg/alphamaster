/**
 * The letters an account is recognised by at a glance.
 *
 * Two initials for a name written in a script with capitals — "Fakhri Al-Najjar" is
 * FA — and one for a name that is not. Arabic has no capitals, and two isolated
 * letters from separate words render as a pair of detached glyphs that read as
 * neither initials nor a word; the first letter alone is how Arabic interfaces mark a
 * person. Graphemes rather than code units, so a name that opens with a combining
 * sequence is not cut in half.
 *
 * Its own module rather than a helper beside the menu that uses it, because a file
 * exporting a component and a function stops hot reloading from working on the
 * component.
 */
export function initialsOf(name: string): string {
    const words = name
        .trim()
        .split(/\s+/)
        .filter((word) => word !== '');

    if (words.length === 0) {
        return '?';
    }

    const first = (word: string) => [...word][0] ?? '';
    const latin = /^[A-Za-z]/.test(words[0] ?? '');

    if (latin && words.length > 1) {
        return (first(words[0] ?? '') + first(words[words.length - 1] ?? '')).toLocaleUpperCase();
    }

    return first(words[0] ?? '').toLocaleUpperCase();
}
