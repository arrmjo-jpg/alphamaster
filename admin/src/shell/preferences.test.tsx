import { act, render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import '@/i18n';

import { DensityProvider } from './DensityProvider';
import { DirectionProvider } from './DirectionProvider';
import { DensityControl, LocaleControl, ThemeControl } from './PreferenceControls';
import { ThemeProvider } from './ThemeProvider';

async function renderControls() {
    const result = render(
        <ThemeProvider>
            <DensityProvider>
                <DirectionProvider>
                    <ThemeControl />
                    <DensityControl />
                    <LocaleControl />
                </DirectionProvider>
            </DensityProvider>
        </ThemeProvider>,
    );

    // The public language list resolves after mount. Flushing it here keeps the
    // provider's state update inside act() instead of racing the assertions.
    await act(async () => {});

    return result;
}

beforeEach(() => {
    localStorage.clear();
    document.documentElement.removeAttribute('data-theme');
    document.documentElement.removeAttribute('data-density');

    // The language list is public and fetched on mount; the switcher must work
    // whether or not it answers.
    vi.stubGlobal(
        'fetch',
        vi.fn(() =>
            Promise.resolve(
                new Response(
                    JSON.stringify({
                        success: true,
                        data: [
                            {
                                code: 'en',
                                name: 'English',
                                native_name: 'English',
                                direction: 'ltr',
                                is_default: true,
                            },
                            {
                                code: 'ar',
                                name: 'Arabic',
                                native_name: 'العربية',
                                direction: 'rtl',
                                is_default: false,
                            },
                        ],
                    }),
                    { status: 200, headers: { 'Content-Type': 'application/json' } },
                ),
            ),
        ),
    );

    vi.stubGlobal(
        'matchMedia',
        vi.fn(() => ({
            matches: false,
            addEventListener: vi.fn(),
            removeEventListener: vi.fn(),
        })),
    );
});

afterEach(() => {
    vi.unstubAllGlobals();
});

describe('display preferences', () => {
    it('applies the theme to the document root so every component inherits it', async () => {
        await renderControls();

        await userEvent.click(screen.getByRole('radio', { name: 'Dark' }));

        expect(document.documentElement.dataset['theme']).toBe('dark');
        expect(localStorage.getItem('alphamaster.theme')).toBe('dark');
    });

    it('defaults to compact, because this is a tool for scanning rather than reading', async () => {
        await renderControls();

        expect(document.documentElement.dataset['density']).toBe('compact');
    });

    it('changes density without touching the theme', async () => {
        await renderControls();
        document.documentElement.dataset['theme'] = 'dark';

        await userEvent.click(screen.getByRole('radio', { name: 'Spacious' }));

        expect(document.documentElement.dataset['density']).toBe('spacious');
        expect(document.documentElement.dataset['theme']).toBe('dark');
    });

    it('turns the document around when the language runs right to left', async () => {
        await renderControls();

        expect(document.documentElement.dir).toBe('ltr');

        await userEvent.click(screen.getByRole('radio', { name: 'العربية' }));

        expect(document.documentElement.lang).toBe('ar');
        expect(document.documentElement.dir).toBe('rtl');
    });

    it('still switches direction when the language list cannot be loaded', async () => {
        vi.stubGlobal(
            'fetch',
            vi.fn(() => Promise.reject(new TypeError('Failed to fetch'))),
        );

        await renderControls();

        await userEvent.click(screen.getByRole('radio', { name: 'العربية' }));

        expect(document.documentElement.dir).toBe('rtl');
    });
});
