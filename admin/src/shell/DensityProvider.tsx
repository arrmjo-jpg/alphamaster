import { createContext, use, useCallback, useEffect, useMemo, useState } from 'react';

/**
 * How much information fits on a screen.
 *
 * Compact is the default, and that is a statement about who this is for: an
 * operator scanning and comparing, not a visitor browsing. Comfortable and
 * spacious exist for reading, for touch, and for anyone who needs the room.
 */
export type Density = 'compact' | 'comfortable' | 'spacious';

const STORAGE_KEY = 'alphamaster.density';
const DEFAULT: Density = 'compact';

interface DensityContextValue {
    density: Density;
    setDensity: (next: Density) => void;
}

const DensityContext = createContext<DensityContextValue | null>(null);

function readStored(): Density {
    try {
        const stored = localStorage.getItem(STORAGE_KEY);

        if (stored === 'compact' || stored === 'comfortable' || stored === 'spacious') {
            return stored;
        }
    } catch {
        // Ignored: an unreadable preference is the default preference.
    }

    return DEFAULT;
}

export function DensityProvider({ children }: { children: React.ReactNode }) {
    const [density, setDensityState] = useState<Density>(readStored);

    useEffect(() => {
        document.documentElement.dataset['density'] = density;
    }, [density]);

    const setDensity = useCallback((next: Density) => {
        setDensityState(next);

        try {
            localStorage.setItem(STORAGE_KEY, next);
        } catch {
            // See above.
        }
    }, []);

    const value = useMemo(() => ({ density, setDensity }), [density, setDensity]);

    return <DensityContext value={value}>{children}</DensityContext>;
}

export function useDensity(): DensityContextValue {
    const context = use(DensityContext);

    if (context === null) {
        throw new Error('useDensity must be used inside DensityProvider');
    }

    return context;
}
