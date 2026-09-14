import { useQuery, useQueryClient } from '@tanstack/react-query';
import { createContext, use, useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';

import { fetchData, setRequestLocale } from '@/api/client';
import type { LanguagesIndexResponses } from '@/api/generated';
import { CATALOGUE_LOCALE } from '@/i18n';

import { applyInterfaceCatalogue, fetchInterfaceCatalogue } from './interfaceCatalogue';

/**
 * Language and writing direction.
 *
 * Language Management is the one source of languages (ADR 0049). `/api/v1/languages` is public
 * and lists every language the platform serves, with its direction and which is the default,
 * and every one of them is a language this console can be read in: its wording comes from the
 * platform when it is chosen, English fills whatever it has not translated, and nothing in this
 * bundle has to know the language exists. Adding one in the Languages workspace offers it here
 * after the next read.
 *
 * Direction is the language's own. Nothing here knows which languages run right to left.
 */

export type Direction = 'ltr' | 'rtl';

const STORAGE_KEY = 'alphamaster.locale';

/** The direction of the language last chosen, for the moment before the list has loaded. */
const DIRECTION_KEY = 'alphamaster.direction';

/** The public list's rows, from the contract rather than restated. */
export type LanguageOption = LanguagesIndexResponses[200]['data'][number];

interface DirectionContextValue {
    locale: string;
    direction: Direction;
    /** Every language the platform serves. */
    languages: LanguageOption[];
    /** The languages this console may be switched to: the same list, in the platform's order. */
    available: LanguageOption[];
    setLocale: (next: string) => void;
}

const DirectionContext = createContext<DirectionContextValue | null>(null);

function readStored(key: string): string | null {
    try {
        const stored = localStorage.getItem(key);

        return stored === null || stored.trim() === '' ? null : stored;
    } catch {
        // Unreadable storage is not a failure; it just means no preference.
        return null;
    }
}

function store(key: string, value: string): void {
    try {
        localStorage.setItem(key, value);
    } catch {
        // See above.
    }
}

export function DirectionProvider({ children }: { children: React.ReactNode }) {
    const { i18n } = useTranslation();
    const queryClient = useQueryClient();
    const [locale, setLocaleState] = useState<string>(
        () => readStored(STORAGE_KEY) ?? CATALOGUE_LOCALE,
    );

    // The list is public, so it loads before sign-in and the login screen can be read in any
    // language. Through the query cache rather than an effect of its own, so that adding or
    // activating a language in the Languages workspace can invalidate it and the switcher
    // follows without a reload.
    const query = useQuery({
        queryKey: ['languages'],
        queryFn: ({ signal }) => fetchData<LanguageOption[]>('/languages', { signal }),
        // The set changes when an administrator changes it, which is rare and is followed by
        // an explicit invalidation.
        staleTime: 5 * 60_000,
    });

    const languages = useMemo(() => query.data ?? [], [query.data]);

    const chosen = languages.find((language) => language.code === locale);
    const storedDirection = readStored(DIRECTION_KEY);

    const direction: Direction =
        chosen?.direction === 'rtl' || chosen?.direction === 'ltr'
            ? chosen.direction
            : storedDirection === 'rtl'
              ? 'rtl'
              : 'ltr';

    const setLocale = useCallback((next: string) => {
        setLocaleState(next);
        store(STORAGE_KEY, next);
    }, []);

    // A locale the platform no longer serves is not a locale. This only runs on a list the
    // platform actually answered with — an unreachable API leaves the operator's choice alone
    // rather than resetting it because a request failed.
    useEffect(() => {
        if (languages.length === 0 || chosen !== undefined) {
            return;
        }

        const fallback = languages.find((language) => language.is_default) ?? languages[0];

        if (fallback !== undefined) {
            setLocale(fallback.code);
        }
    }, [languages, chosen, setLocale]);

    useEffect(() => {
        const root = document.documentElement;
        root.lang = locale;
        root.dir = direction;
        store(DIRECTION_KEY, direction);
    }, [locale, direction]);

    // The chosen language's wording, from the platform. English ships in the bundle and is
    // never fetched. Keyed by locale so that accepting a translation in the workshop can
    // invalidate it and the console reads the new wording on its next render.
    const catalogue = useQuery({
        queryKey: ['interface-catalogue', locale],
        queryFn: ({ signal }) => fetchInterfaceCatalogue(locale, signal),
        enabled: locale !== CATALOGUE_LOCALE,
        staleTime: 5 * 60_000,
        retry: false,
    });

    useEffect(() => {
        if (locale === CATALOGUE_LOCALE) {
            void i18n.changeLanguage(locale);

            return;
        }

        if (catalogue.data !== undefined) {
            applyInterfaceCatalogue(locale, catalogue.data);
        }

        // Switched once the wording has arrived, or once it is known that it will not: a
        // language with nothing translated, or an unreachable API, reads in English key by
        // key rather than in raw keys.
        if (catalogue.data !== undefined || catalogue.isError) {
            void i18n.changeLanguage(locale);
        }
    }, [i18n, locale, catalogue.data, catalogue.isError]);

    // Every request from here on asks the platform to answer in this language. The labels the
    // API publishes beside its identifiers (ADR 0030) are resolved per request.
    //
    // Changing it invalidates the cache, because a cached answer was rendered in the language
    // that asked for it. Not on the first render: nothing is cached yet, and invalidating
    // would send every query twice on load.
    const settled = useRef(false);

    useEffect(() => {
        setRequestLocale(locale);

        if (!settled.current) {
            settled.current = true;

            return;
        }

        void queryClient.invalidateQueries();
    }, [locale, queryClient]);

    const value = useMemo(
        () => ({ locale, direction, languages, available: languages, setLocale }),
        [locale, direction, languages, setLocale],
    );

    return <DirectionContext value={value}>{children}</DirectionContext>;
}

export function useDirection(): DirectionContextValue {
    const context = use(DirectionContext);

    if (context === null) {
        throw new Error('useDirection must be used inside DirectionProvider');
    }

    return context;
}
