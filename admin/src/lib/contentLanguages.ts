/**
 * Content languages and translation state, shared by every screen that edits localized
 * content (ADR 0043 §4a, ADR 0055 §8).
 *
 * The languages always come from Language Management. Nothing here holds a list of codes.
 */

/**
 * The fields of a language the content controls read. Structural rather than the generated
 * type, so any screen that has Language Management's list can pass it unchanged.
 */
export interface ContentLanguageOption {
    code: string;
    native_name: string;
    is_default: boolean;
    is_active: boolean;
    sort_order?: number;
}

/** The default language first, then the configured order. */
export function orderedLanguages<T extends ContentLanguageOption>(languages: readonly T[]): T[] {
    return languages.toSorted((a, b) => {
        if (a.is_default !== b.is_default) {
            return a.is_default ? -1 : 1;
        }

        return (a.sort_order ?? 0) - (b.sort_order ?? 0);
    });
}

/** The language a screen opens on: the platform default, or the first it knows. */
export function initialContentLanguage(languages: readonly ContentLanguageOption[]): string | null {
    return orderedLanguages(languages)[0]?.code ?? null;
}

/**
 * What has been written in one language.
 *
 * Counted from what is saved, never from a fallback: a language whose fields would read the
 * default language's text is not translated, and saying otherwise is the thing the status list
 * exists to prevent (ADR 0043 §4).
 */
export interface TranslationState {
    /** Fields with text of their own. */
    filled: number;
    total: number;
    /** Whether every field the owner requires for this language to be served has text. */
    complete: boolean;
}

export type TranslationStatus = 'translated' | 'incomplete' | 'missing';

export function translationStatus(state: TranslationState | undefined): TranslationStatus {
    if (state === undefined || state.filled === 0) {
        return 'missing';
    }

    return state.complete ? 'translated' : 'incomplete';
}
