/** Text as a field value, or null to clear it: an empty field means nothing is written. */
export function textOrNull(value: string): string | null {
    return value.trim() === '' ? null : value;
}

/** The native names of some language codes, for "public in" lines. */
export function languageNames(
    codes: readonly string[],
    languages: readonly { code: string; native_name: string }[],
): string {
    return codes
        .map((code) => languages.find((language) => language.code === code)?.native_name ?? code)
        .join(', ');
}
