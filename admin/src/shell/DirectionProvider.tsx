import { useQuery } from '@tanstack/react-query';
import { createContext, use, useCallback, useEffect, useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';

import { fetchData } from '@/api/client';
import type { LanguagesIndexResponses } from '@/api/generated';
import { FALLBACK_LOCALE, isSupportedLocale, type SupportedLocale } from '@/i18n';

/**
 * Language and writing direction.
 *
 * The backend owns which languages exist, which are active and which way each one runs
 * (ADR 0015), and `/api/v1/languages` is public, so this asks rather than assumes. The
 * static map below is the fallback for the one case where asking is impossible — the
 * API is unreachable — because an admin that renders nothing when the network hiccups
 * is worse than one that renders in the wrong direction for a moment.
 *
 * Two different things decide what an operator may switch to, and both have to hold:
 *
 *   the platform  — the language is in `/languages`, which returns the active ones
 *   this bundle   — the Admin ships a message catalogue for it
 *
 * The first is data and changes without a deploy; the second is a fact about the build
 * and cannot. So the offered set is the intersection, computed rather than listed, and
 * deactivating a language in the Languages workspace removes it from the switcher on
 * the next read instead of on the next release.
 */

export type Direction = 'ltr' | 'rtl';

const STORAGE_KEY = 'alphamaster.locale';

const KNOWN_DIRECTIONS: Record<SupportedLocale, Direction> = { en: 'ltr', ar: 'rtl' };

/** The public list's rows, from the contract rather than restated. */
export type LanguageOption = LanguagesIndexResponses[200]['data'][number];

interface DirectionContextValue {
    locale: SupportedLocale;
    direction: Direction;
    /** Every active language the platform serves, translated interface or not. */
    languages: LanguageOption[];
    /** The subset this console can actually be read in, in the platform's order. */
    available: LanguageOption[];
    setLocale: (next: SupportedLocale) => void;
}

const DirectionContext = createContext<DirectionContextValue | null>(null);

function readStoredLocale(): SupportedLocale {
    try {
        const stored = localStorage.getItem(STORAGE_KEY);

        if (stored !== null && isSupportedLocale(stored)) {
            return stored;
        }
    } catch {
        // Unreadable storage is not a failure; it just means no preference.
    }

    return FALLBACK_LOCALE;
}

export function DirectionProvider({ children }: { children: React.ReactNode }) {
    const { i18n } = useTranslation();
    const [locale, setLocaleState] = useState<SupportedLocale>(readStoredLocale);

    // The list is public, so it loads before sign-in and the login screen can be read
    // in either language. Through the query cache rather than an effect of its own, so
    // that activating a language in the Languages workspace can invalidate it and the
    // switcher follows without a reload.
    const query = useQuery({
        queryKey: ['languages'],
        queryFn: ({ signal }) => fetchData<LanguageOption[]>('/languages', { signal }),
        // The set changes when an administrator changes it, which is rare and is
        // followed by an explicit invalidation.
        staleTime: 5 * 60_000,
    });

    const languages = useMemo(() => query.data ?? [], [query.data]);

    const available = useMemo(
        () => languages.filter((language) => isSupportedLocale(language.code)),
        [languages],
    );

    const direction: Direction =
        languages.find((language) => language.code === locale)?.direction ??
        KNOWN_DIRECTIONS[locale];

    const setLocale = useCallback((next: SupportedLocale) => {
        setLocaleState(next);

        try {
            localStorage.setItem(STORAGE_KEY, next);
        } catch {
            // See above.
        }
    }, []);

    // A locale the platform no longer serves is not a locale. This only runs on a list
    // the platform actually answered with — an unreachable API leaves the operator's
    // choice alone rather than resetting it because a request failed.
    useEffect(() => {
        if (available.length === 0 || available.some((language) => language.code === locale)) {
            return;
        }

        const fallback =
            available.find((language) => language.is_default) ?? available[0] ?? undefined;

        if (fallback !== undefined && isSupportedLocale(fallback.code)) {
            setLocale(fallback.code);
        }
    }, [available, locale, setLocale]);

    useEffect(() => {
        const root = document.documentElement;
        root.lang = locale;
        root.dir = direction;
    }, [locale, direction]);

    useEffect(() => {
        void i18n.changeLanguage(locale);
    }, [i18n, locale]);

    const value = useMemo(
        () => ({ locale, direction, languages, available, setLocale }),
        [locale, direction, languages, available, setLocale],
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
