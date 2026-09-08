import { createContext, use, useCallback, useEffect, useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';

import { fetchData } from '@/api/client';
import { FALLBACK_LOCALE, isSupportedLocale, type SupportedLocale } from '@/i18n';

/**
 * Language and writing direction.
 *
 * The backend owns which languages exist and which way each one runs (ADR 0015),
 * and `/api/v1/languages` is public, so this asks rather than assumes. The static
 * map below is the fallback for the one case where asking is impossible — the API
 * is unreachable — because an admin that renders nothing when the network hiccups
 * is worse than one that renders in the wrong direction for a moment.
 */

export type Direction = 'ltr' | 'rtl';

const STORAGE_KEY = 'alphamaster.locale';

const KNOWN_DIRECTIONS: Record<SupportedLocale, Direction> = { en: 'ltr', ar: 'rtl' };

interface LanguageOption {
    code: string;
    name: string;
    native_name: string;
    direction: Direction;
    is_default: boolean;
}

interface DirectionContextValue {
    locale: SupportedLocale;
    direction: Direction;
    languages: LanguageOption[];
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
    const [languages, setLanguages] = useState<LanguageOption[]>([]);

    // The list is public, so it loads before sign-in and the login screen can be
    // read in either language.
    useEffect(() => {
        const controller = new AbortController();

        void fetchData<LanguageOption[]>('/languages', { signal: controller.signal })
            .then((list) => setLanguages(list ?? []))
            .catch(() => {
                // Deliberately silent: a missing language list degrades the switcher,
                // it does not break the application.
            });

        return () => controller.abort();
    }, []);

    const direction: Direction =
        languages.find((language) => language.code === locale)?.direction ??
        KNOWN_DIRECTIONS[locale];

    useEffect(() => {
        const root = document.documentElement;
        root.lang = locale;
        root.dir = direction;
    }, [locale, direction]);

    useEffect(() => {
        void i18n.changeLanguage(locale);
    }, [i18n, locale]);

    const setLocale = useCallback((next: SupportedLocale) => {
        setLocaleState(next);

        try {
            localStorage.setItem(STORAGE_KEY, next);
        } catch {
            // See above.
        }
    }, []);

    const value = useMemo(
        () => ({ locale, direction, languages, setLocale }),
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
