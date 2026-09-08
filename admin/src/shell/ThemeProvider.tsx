import { createContext, use, useCallback, useEffect, useMemo, useState } from 'react';

/**
 * Light, dark, or whatever the operating system says.
 *
 * `system` is the default and is a real third option rather than a synonym for
 * light: a control room that has chosen dark at the OS level should not be handed
 * a white screen because this application had an opinion.
 */
export type ThemePreference = 'light' | 'dark' | 'system';

const STORAGE_KEY = 'alphamaster.theme';

interface ThemeContextValue {
    preference: ThemePreference;
    resolved: 'light' | 'dark';
    setPreference: (next: ThemePreference) => void;
}

const ThemeContext = createContext<ThemeContextValue | null>(null);

function readStoredPreference(): ThemePreference {
    // A display preference, not session state — it belongs to this browser and
    // nothing about authentication passes through here.
    try {
        const stored = localStorage.getItem(STORAGE_KEY);

        if (stored === 'light' || stored === 'dark' || stored === 'system') {
            return stored;
        }
    } catch {
        // Private windows and blocked site data both throw. Neither is an error.
    }

    return 'system';
}

function systemPrefersDark(): boolean {
    return window.matchMedia?.('(prefers-color-scheme: dark)').matches ?? false;
}

export function ThemeProvider({ children }: { children: React.ReactNode }) {
    const [preference, setPreferenceState] = useState<ThemePreference>(readStoredPreference);
    const [systemDark, setSystemDark] = useState<boolean>(systemPrefersDark);

    useEffect(() => {
        const query = window.matchMedia?.('(prefers-color-scheme: dark)');

        if (!query) {
            return;
        }

        const listener = (event: MediaQueryListEvent) => setSystemDark(event.matches);
        query.addEventListener('change', listener);

        return () => query.removeEventListener('change', listener);
    }, []);

    const resolved: 'light' | 'dark' =
        preference === 'system' ? (systemDark ? 'dark' : 'light') : preference;

    useEffect(() => {
        document.documentElement.dataset['theme'] = resolved;
    }, [resolved]);

    const setPreference = useCallback((next: ThemePreference) => {
        setPreferenceState(next);

        try {
            localStorage.setItem(STORAGE_KEY, next);
        } catch {
            // Losing the preference is survivable; failing to switch is not.
        }
    }, []);

    const value = useMemo(
        () => ({ preference, resolved, setPreference }),
        [preference, resolved, setPreference],
    );

    return <ThemeContext value={value}>{children}</ThemeContext>;
}

export function useTheme(): ThemeContextValue {
    const context = use(ThemeContext);

    if (context === null) {
        throw new Error('useTheme must be used inside ThemeProvider');
    }

    return context;
}
