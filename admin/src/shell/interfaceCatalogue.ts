import { fetchData } from '@/api/client';
import i18n from '@/i18n';

/**
 * The console's wording in one language, as the platform serves it (ADR 0049).
 *
 * The English catalogue ships in the bundle and is the source of every key. Every other
 * language — Arabic included — is fetched at runtime: the platform merges what ships with it
 * and what operators have translated since, so a language added in Language Management is a
 * console language after a refresh, with no build. Whatever a language has not translated
 * falls back to English key by key; that is display, not translation, and the workshop still
 * reports those keys as not translated.
 */

export type InterfaceCatalogue = Record<string, unknown>;

/** Every plural category a language can need (CLDR). */
const PLURAL_CATEGORIES = ['zero', 'one', 'two', 'few', 'many', 'other'] as const;

function pluralCategoriesOf(locale: string): readonly string[] {
    try {
        return new Intl.PluralRules(locale).resolvedOptions().pluralCategories;
    } catch {
        // A code the runtime does not recognise still gets the forms English has.
        return ['one', 'other'];
    }
}

/**
 * Fill the plural forms a language needs and its translation lacks from `_other`.
 *
 * English has two plural forms and Arabic six. A language translated from English arrives with
 * English's two, and i18next would render a raw key for any count that needs a third; the
 * `_other` wording is a far better answer than a key.
 */
export function completePlurals(catalogue: InterfaceCatalogue, locale: string): InterfaceCatalogue {
    const categories = pluralCategoriesOf(locale).filter((category) =>
        (PLURAL_CATEGORIES as readonly string[]).includes(category),
    );

    const complete = (node: InterfaceCatalogue): InterfaceCatalogue => {
        const result: InterfaceCatalogue = {};

        for (const [key, value] of Object.entries(node)) {
            result[key] =
                value !== null && typeof value === 'object'
                    ? complete(value as InterfaceCatalogue)
                    : value;
        }

        for (const [key, value] of Object.entries(node)) {
            if (typeof value !== 'string' || !key.endsWith('_other')) {
                continue;
            }

            const base = key.slice(0, -'_other'.length);

            for (const category of categories) {
                result[`${base}_${category}`] ??= value;
            }
        }

        return result;
    };

    return complete(catalogue);
}

export async function fetchInterfaceCatalogue(
    locale: string,
    signal?: AbortSignal,
): Promise<InterfaceCatalogue> {
    return fetchData<InterfaceCatalogue>(`/interface/console/${encodeURIComponent(locale)}`, {
        ...(signal ? { signal } : {}),
    });
}

/** Make a fetched catalogue the wording i18next reads for its language. */
export function applyInterfaceCatalogue(locale: string, catalogue: InterfaceCatalogue): void {
    i18n.addResourceBundle(locale, 'common', completePlurals(catalogue, locale), true, true);
}
