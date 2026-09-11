import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { act, render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import '@/i18n';

import type { MenuRadioOption } from '@/ui/Menu';

import { DensityProvider } from './DensityProvider';
import { DirectionProvider } from './DirectionProvider';
import { AppearanceControl, LocaleControl, type LocaleControlProps } from './PreferenceControls';
import { ThemeProvider } from './ThemeProvider';

/**
 * The viewer's preferences, as the top bar now offers them.
 *
 * What changed is the control, not the preference: theme, density and language are
 * stored exactly where they were, and these assert that folding them into menus did
 * not cost any of the three its behaviour. The last group is about the menu itself —
 * a list that comes from a database table has to survive being long, and that is the
 * property a row of buttons could not have.
 */

async function renderControls(localeProps?: LocaleControlProps) {
    // The direction provider reads the public language list through the query cache,
    // so it needs a client. Retries are off here and only here: the failure case below
    // asserts what the switcher does once the list is known to be unavailable, and
    // waiting out two backoffs would test the retry policy instead.
    const queryClient = new QueryClient({
        defaultOptions: { queries: { retry: false } },
    });

    const result = render(
        <QueryClientProvider client={queryClient}>
            <ThemeProvider>
                <DensityProvider>
                    <DirectionProvider>
                        <AppearanceControl />
                        <LocaleControl {...localeProps} />
                    </DirectionProvider>
                </DensityProvider>
            </ThemeProvider>
        </QueryClientProvider>,
    );

    // The public language list resolves after mount. Flushing it here keeps the
    // provider's state update inside act() instead of racing the assertions.
    await act(async () => {});

    return result;
}

/** Open a menu by its trigger's accessible name and return its panel. */
async function open(name: string) {
    await userEvent.click(screen.getByRole('button', { name }));

    return screen.getByRole('menu', { name });
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

describe('appearance', () => {
    it('is one menu rather than six buttons in the chrome', async () => {
        await renderControls();

        // The thing this replaced: three theme segments and three density segments,
        // all of them permanently visible.
        expect(screen.queryByRole('radio', { name: 'Dark' })).not.toBeInTheDocument();
        expect(screen.queryByRole('radio', { name: 'Spacious' })).not.toBeInTheDocument();

        const panel = await open('Appearance');

        expect(within(panel).getByRole('menuitemradio', { name: /Dark/ })).toBeInTheDocument();
        expect(within(panel).getByRole('menuitemradio', { name: /Spacious/ })).toBeInTheDocument();
    });

    it('applies the theme to the document root so every component inherits it', async () => {
        await renderControls();

        const panel = await open('Appearance');
        await userEvent.click(within(panel).getByRole('menuitemradio', { name: /Dark/ }));

        expect(document.documentElement.dataset['theme']).toBe('dark');
        expect(localStorage.getItem('alphamaster.theme')).toBe('dark');
    });

    it('keeps light, dark and system all reachable', async () => {
        await renderControls();

        for (const [name, expected] of [
            ['Dark', 'dark'],
            ['Light', 'light'],
            ['System', 'system'],
        ] as const) {
            const panel = await open('Appearance');
            await userEvent.click(within(panel).getByRole('menuitemradio', { name: RegExp(name) }));

            expect(localStorage.getItem('alphamaster.theme')).toBe(expected);
        }
    });

    it('marks the current choice, which a closed menu cannot show any other way', async () => {
        await renderControls();

        const panel = await open('Appearance');

        expect(within(panel).getByRole('menuitemradio', { name: /System/ })).toHaveAttribute(
            'aria-checked',
            'true',
        );
        expect(within(panel).getByRole('menuitemradio', { name: /Dark/ })).toHaveAttribute(
            'aria-checked',
            'false',
        );
    });

    it('defaults to compact, because this is a tool for scanning rather than reading', async () => {
        await renderControls();

        expect(document.documentElement.dataset['density']).toBe('compact');
    });

    it('changes density without touching the theme', async () => {
        await renderControls();
        document.documentElement.dataset['theme'] = 'dark';

        const panel = await open('Appearance');
        await userEvent.click(within(panel).getByRole('menuitemradio', { name: /Spacious/ }));

        expect(document.documentElement.dataset['density']).toBe('spacious');
        expect(document.documentElement.dataset['theme']).toBe('dark');
    });

    it('closes on Escape and gives focus back to its trigger', async () => {
        await renderControls();

        const trigger = screen.getByRole('button', { name: 'Appearance' });
        await open('Appearance');

        await userEvent.keyboard('{Escape}');

        expect(screen.queryByRole('menu', { name: 'Appearance' })).not.toBeInTheDocument();
        expect(trigger).toHaveFocus();
    });
});

describe('the language switcher', () => {
    it('is a single menu naming the language in force', async () => {
        await renderControls();

        const trigger = screen.getByRole('button', { name: 'Language' });

        // Not one button per language, which is what does not scale.
        expect(trigger).toHaveTextContent('English');
        expect(screen.queryByRole('radio', { name: 'العربية' })).not.toBeInTheDocument();
    });

    it('turns the document around when the language runs right to left', async () => {
        await renderControls();

        expect(document.documentElement.dir).toBe('ltr');

        const panel = await open('Language');
        await userEvent.click(within(panel).getByRole('menuitemradio', { name: /العربية/ }));

        expect(document.documentElement.lang).toBe('ar');
        expect(document.documentElement.dir).toBe('rtl');
    });

    it('still switches direction when the language list cannot be loaded', async () => {
        vi.stubGlobal(
            'fetch',
            vi.fn(() => Promise.reject(new TypeError('Failed to fetch'))),
        );

        await renderControls();

        const panel = await open('Language');
        await userEvent.click(within(panel).getByRole('menuitemradio', { name: /العربية/ }));

        expect(document.documentElement.dir).toBe('rtl');
    });

    it('announces the language instead of offering a choice of one', async () => {
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
                            ],
                        }),
                        { status: 200, headers: { 'Content-Type': 'application/json' } },
                    ),
                ),
            ),
        );

        await renderControls();

        // Waited for rather than asserted immediately: until the list answers there
        // is no knowing it holds one row, and the fallback offers both catalogues the
        // bundle ships. The switcher collapsing is a consequence of the answer.
        expect(await screen.findByText('Interface language: English')).toBeInTheDocument();
        expect(screen.queryByRole('button', { name: 'Language' })).not.toBeInTheDocument();
    });

    it('marks the current language with a check rather than by colour', async () => {
        await renderControls();

        const panel = await open('Language');

        expect(within(panel).getByRole('menuitemradio', { name: /English/ })).toHaveAttribute(
            'aria-checked',
            'true',
        );
    });
});

/**
 * The visual indicator beside each language.
 *
 * A flag when the platform said which region the entry is for, and a globe when it did
 * not. What these guard is the second half: the console must not decide that Arabic
 * means Jordan because it wanted something colourful in the list.
 */
describe('the language indicator', () => {
    it('shows a flag when the entry carries a region', async () => {
        await renderControls({
            options: [
                { value: 'en', label: 'English', hint: 'EN' },
                { value: 'ar-JO', label: 'العربية', hint: 'AR-JO' },
            ],
        });

        const panel = await open('Language');
        const row = within(panel).getByRole('menuitemradio', { name: /العربية/ });

        // Jordan, because `ar-JO` says Jordan — not because Arabic was assumed to be.
        expect(row.textContent).toContain('🇯🇴');
    });

    it('shows a globe, not a guessed country, when the entry carries no region', async () => {
        // The platform's actual state today: bare ISO-639-1 codes.
        await renderControls();

        const panel = await open('Language');

        for (const name of [/English/, /العربية/]) {
            const row = within(panel).getByRole('menuitemradio', { name });
            expect(row.querySelector('svg.lucide-globe')).not.toBeNull();
        }
    });

    it('does not announce the flag, because the row is a language not a country', async () => {
        await renderControls({
            options: [
                { value: 'ar-JO', label: 'العربية', hint: 'AR-JO' },
                { value: 'en', label: 'English', hint: 'EN' },
            ],
        });

        const panel = await open('Language');
        const row = within(panel).getByRole('menuitemradio', { name: /العربية/ });

        // The flag is drawn and hidden from the accessibility tree. A screen reader
        // reading "Jordan" to someone offered a language would be reading them a fact
        // about a country they never asked about.
        const flag = [...row.querySelectorAll('span')].find(
            (element) => element.textContent === '🇯🇴',
        );

        expect(flag).toBeDefined();
        expect(flag).toHaveAttribute('aria-hidden', 'true');
    });

    it('refuses a region the platform made up', async () => {
        await renderControls({
            options: [
                { value: 'en', label: 'English', hint: 'EN' },
                // `ZZ` is CLDR's "Unknown Region" and `419` is a continent. Neither
                // has a flag, and neither may borrow one.
                { value: 'es-ZZ', label: 'Español', hint: 'ES-ZZ' },
                { value: 'es-419', label: 'Español (Latinoamérica)', hint: 'ES-419' },
            ],
        });

        const panel = await open('Language');

        // Matched on the code, because the accessible name carries it alongside the
        // language and the two Spanish rows differ only there.
        for (const name of [/ES-ZZ/, /ES-419/]) {
            const row = within(panel).getByRole('menuitemradio', { name });
            expect(row.querySelector('svg.lucide-globe')).not.toBeNull();
        }
    });
});

/**
 * The platform's language table can hold any number of rows, and this console has to
 * stay usable at the top of that range rather than at the bottom of it. Sixty is not a
 * realistic count for the two catalogues shipped today — it is the count that proves
 * the control is not sized to today.
 */
describe('the language switcher, with a language table that grew', () => {
    const MANY: MenuRadioOption<string>[] = Array.from({ length: 60 }, (_, index) => ({
        value: index === 0 ? 'en' : `l${index}`,
        label: index === 0 ? 'English' : `Language ${index}`,
        hint: (index === 0 ? 'en' : `l${index}`).toLocaleUpperCase(),
        keywords: index === 0 ? 'English' : `keyword-${index}`,
    }));

    it('offers a filter instead of sixty rows to scroll past', async () => {
        await renderControls({ options: MANY });

        const panel = await open('Language');

        expect(within(panel).getAllByRole('menuitemradio')).toHaveLength(60);

        const search = within(panel).getByRole('searchbox', { name: 'Search languages' });
        await userEvent.type(search, 'Language 42');

        const remaining = within(panel).getAllByRole('menuitemradio');
        expect(remaining).toHaveLength(1);
        expect(remaining[0]).toHaveTextContent('Language 42');
    });

    it('puts the cursor in the filter on open, so typing narrows immediately', async () => {
        await renderControls({ options: MANY });

        const panel = await open('Language');

        expect(within(panel).getByRole('searchbox', { name: 'Search languages' })).toHaveFocus();
    });

    it('matches the English name as well as the one the language calls itself', async () => {
        // A reader who knows a language by its English name should not have to type it
        // in a script they cannot produce.
        await renderControls({
            options: [
                { value: 'en', label: 'English', hint: 'EN', keywords: 'English' },
                { value: 'ar', label: 'العربية', hint: 'AR', keywords: 'Arabic' },
                ...MANY.slice(2),
            ],
        });

        const panel = await open('Language');
        await userEvent.type(
            within(panel).getByRole('searchbox', { name: 'Search languages' }),
            'arabic',
        );

        const remaining = within(panel).getAllByRole('menuitemradio');
        expect(remaining).toHaveLength(1);
        expect(remaining[0]).toHaveTextContent('العربية');
    });

    it('says so when nothing matches, rather than showing an empty panel', async () => {
        await renderControls({ options: MANY });

        const panel = await open('Language');
        await userEvent.type(
            within(panel).getByRole('searchbox', { name: 'Search languages' }),
            'zzzzz',
        );

        expect(within(panel).queryAllByRole('menuitemradio')).toHaveLength(0);
        expect(within(panel).getByText('No language matches that.')).toBeInTheDocument();
    });

    it('leaves the short list unfiltered, because two rows need no search box', async () => {
        await renderControls();

        const panel = await open('Language');

        expect(within(panel).queryByRole('searchbox')).not.toBeInTheDocument();
    });

    it('scrolls the long list rather than growing past the window', async () => {
        await renderControls({ options: MANY });

        const panel = await open('Language');
        const list = within(panel).getAllByRole('menuitemradio')[0]?.parentElement;

        // A bounded, scrolling region is what keeps a sixty-row menu inside a 375px
        // viewport; without it the panel is taller than the screen and the last rows
        // are unreachable.
        expect(list?.className).toContain('overflow-y-auto');
        expect(list?.className).toContain('max-h-');
    });
});
