import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { HttpResponse, http } from 'msw';
import { MemoryRouter, Route, Routes, useLocation } from 'react-router';
import { afterEach, describe, expect, it, vi } from 'vitest';

import { AppProviders } from '@/app/AppProviders';
import { AuthGate } from '@/auth/AuthGate';
import { LanguagesScreen } from '@/screens/LanguagesScreen';
import { LocaleControl } from '@/shell/PreferenceControls';
import { observed, server } from '@/test/server';

import '@/i18n';

/**
 * Languages, against the six operations the platform actually has.
 *
 * The distinction under test is the one the screen exists to keep: the languages the
 * platform serves are data, and the languages this console can be read in are a fact
 * about the bundle. The switcher is the intersection, and adding a language the Admin
 * ships no catalogue for must say so rather than quietly producing a half-English
 * interface.
 */

/**
 * jsdom applies no CSS, so a layout that switches structure has to switch on
 * `matchMedia` for a test to see either half of it.
 */
function viewport(wide: boolean) {
    vi.stubGlobal(
        'matchMedia',
        (query: string): MediaQueryList =>
            ({
                matches: wide && query.includes('min-width'),
                media: query,
                addEventListener: () => {},
                removeEventListener: () => {},
            }) as unknown as MediaQueryList,
    );
}

afterEach(() => {
    vi.unstubAllGlobals();
});

const HEALTH = http.get('*/api/v1/health', () =>
    HttpResponse.json({
        success: true,
        data: {
            status: 'healthy',
            timestamp: '2026-09-08T10:00:00+00:00',
            framework: 'Laravel 13',
        },
    }),
);

function language(over: Record<string, unknown> = {}) {
    return {
        id: 'lang-en',
        code: 'en',
        name: 'English',
        native_name: 'English',
        direction: 'ltr',
        is_active: true,
        is_default: true,
        sort_order: 0,
        created_at: '2026-01-01T00:00:00+00:00',
        updated_at: '2026-01-01T00:00:00+00:00',
        ...over,
    };
}

const ARABIC = language({
    id: 'lang-ar',
    code: 'ar',
    name: 'Arabic',
    native_name: 'العربية',
    direction: 'rtl',
    is_default: false,
    sort_order: 1,
});

const NO_SUGGESTIONS = { pending: 0, ready: 0, failed: 0, accepted: 0, dismissed: 0 };

function overview(
    ai: { available: boolean; may_use: boolean } = { available: true, may_use: true },
    languages: unknown[] = [
        { code: 'en', coverage: { total: 10, translated: 10 }, suggestions: NO_SUGGESTIONS },
        { code: 'ar', coverage: { total: 10, translated: 8 }, suggestions: NO_SUGGESTIONS },
    ],
) {
    return { source_locale: 'en', ai, languages };
}

/** Where a navigation landed, so a test can read the address a button went to. */
function Landed() {
    const location = useLocation();

    return <p data-testid="landed">{`${location.pathname}${location.search}`}</p>;
}

function renderScreen(
    rows: ReturnType<typeof language>[] = [language(), ARABIC],
    publicRows: ReturnType<typeof language>[] = rows.filter((row) => row.is_active),
    standing: ReturnType<typeof overview> = overview(),
) {
    server.use(
        HEALTH,
        http.get('*/api/v1/languages', () =>
            HttpResponse.json({ success: true, data: publicRows }),
        ),
        http.get('*/api/v1/admin/languages', () =>
            HttpResponse.json({ success: true, data: rows }),
        ),
        http.get('*/api/v1/admin/translations/overview', () =>
            HttpResponse.json({ success: true, data: standing }),
        ),
        http.get('*/api/v1/auth/me', () =>
            HttpResponse.json({
                success: true,
                data: {
                    id: '01hzzadmin',
                    name: 'Nadia Haddad',
                    email: 'nadia@example.test',
                    account_type: 'admin',
                    is_active: true,
                    email_verified: true,
                    email_verified_at: '2026-01-01T00:00:00+00:00',
                    abilities: ['admin:access'],
                    roles: ['administrator'],
                    permissions: [],
                },
            }),
        ),
    );

    return render(
        <MemoryRouter initialEntries={['/languages']}>
            <AppProviders>
                <AuthGate>
                    <Routes>
                        <Route element={<LanguagesScreen />} path="/languages" />
                        <Route element={<Landed />} path="/translations" />
                    </Routes>
                </AuthGate>
            </AppProviders>
        </MemoryRouter>,
    );
}

describe('the languages workspace', () => {
    it('lists what the platform serves, marking the default and the direction', async () => {
        renderScreen();

        // English names itself "English", so the row carries it twice — once as the
        // name and once as the native name. That is the data, not a duplicate render.
        expect(await screen.findAllByText('English')).toHaveLength(2);
        expect(screen.getByText('Arabic')).toBeInTheDocument();
        expect(screen.getByText('العربية')).toBeInTheDocument();
        // A regex, because on the record layout the direction sits in a line with the
        // code and the order rather than in a cell of its own.
        expect(screen.getAllByText(/Right to left/).length).toBeGreaterThan(0);
        expect(screen.getAllByText('Default')).toHaveLength(1);
    });

    it('offers no delete, because the API has none, and says why', async () => {
        renderScreen();

        await userEvent.click(await screen.findByText('Arabic'));

        expect(screen.queryByRole('button', { name: /delete|remove/i })).not.toBeInTheDocument();
        expect(screen.getByText(/A language cannot be deleted/)).toBeInTheDocument();
    });

    it('refuses to switch off the default, as the platform does', async () => {
        renderScreen();

        const [english] = await screen.findAllByText('English');
        await userEvent.click(english as HTMLElement);

        expect(screen.getByRole('button', { name: 'Deactivate' })).toBeDisabled();
        expect(screen.getByText(/default language cannot be switched off/)).toBeInTheDocument();
    });

    it('sends only what changed when a language is edited', async () => {
        server.use(
            http.put('*/api/v1/admin/languages/:id', () =>
                HttpResponse.json({ success: true, data: { ...ARABIC, name: 'Arabic (Jordan)' } }),
            ),
        );

        renderScreen();

        await userEvent.click(await screen.findByText('Arabic'));

        // A required field's label carries its asterisk, so this matches on the words.
        const name = screen.getByLabelText(/Name in English/);
        await userEvent.clear(name);
        await userEvent.type(name, 'Arabic (Jordan)');
        await userEvent.click(screen.getByRole('button', { name: 'Save language' }));

        const write = await waitForRequest('PUT');

        expect(await write.json()).toEqual({ name: 'Arabic (Jordan)' });
    });

    it('warns that a language the console has no catalogue for will not translate it', async () => {
        renderScreen();

        await userEvent.click(await screen.findByRole('button', { name: 'Add a language' }));

        const code = await screen.findByLabelText(/Code/);
        await userEvent.type(code, 'ku');

        expect(screen.getByText(/ships no message catalogue for ku/)).toBeInTheDocument();
    });

    it('reaches a language from the keyboard on a wide screen, not only by pointer', async () => {
        // A table row is not focusable and has no keyboard activation, so the row
        // handler alone would leave this table reachable by pointer only.
        viewport(true);

        renderScreen();

        const [row] = await screen.findAllByRole('button', { name: /English/ });

        expect(row).toBeDefined();

        row?.focus();
        expect(row).toHaveFocus();

        await userEvent.keyboard('{Enter}');

        expect(await screen.findByText(/A language cannot be deleted/)).toBeInTheDocument();
    });

    it('marks which languages the console itself is translated into', async () => {
        renderScreen([
            language(),
            ARABIC,
            language({ id: 'lang-ku', code: 'ku', name: 'Kurdish', native_name: 'کوردی' }),
        ]);

        await screen.findByText('Kurdish');

        expect(screen.getAllByText('Console translated')).toHaveLength(2);
        expect(screen.getAllByText('Console not translated')).toHaveLength(1);
    });
});

describe('the language workflow', () => {
    const FRENCH = language({
        id: 'lang-fr',
        code: 'fr',
        name: 'French',
        native_name: 'Français',
        is_active: false,
        is_default: false,
        sort_order: 3,
    });

    it('shows each language’s coverage as the platform counts it', async () => {
        renderScreen();

        expect(await screen.findByText('80%')).toBeInTheDocument();
        expect(screen.getByText('100%')).toBeInTheDocument();
    });

    it('marks a draft as not served', async () => {
        renderScreen([language(), ARABIC, FRENCH]);

        expect(await screen.findByText('Draft · Not served')).toBeInTheDocument();
    });

    it('says whether AI can help, and treats the default as the source', async () => {
        renderScreen();

        expect(await screen.findByText('AI ready')).toBeInTheDocument();
        expect(screen.getByText('Source')).toBeInTheDocument();
    });

    it('says AI is not configured rather than offering it', async () => {
        renderScreen(undefined, undefined, overview({ available: false, may_use: true }));

        expect(await screen.findByText('Not configured')).toBeInTheDocument();
    });

    it('says AI is not permitted for an operator who may not spend on it', async () => {
        renderScreen(undefined, undefined, overview({ available: true, may_use: false }));

        expect(await screen.findByText('Not permitted')).toBeInTheDocument();
    });

    it('adds a language as a draft unless told to serve it', async () => {
        const bodies: Array<Record<string, unknown>> = [];

        server.use(
            http.post('*/api/v1/admin/languages', async ({ request }) => {
                bodies.push((await request.json()) as Record<string, unknown>);

                return HttpResponse.json(
                    { success: true, message: 'created', data: FRENCH },
                    { status: 201 },
                );
            }),
        );

        renderScreen();

        await userEvent.click(await screen.findByRole('button', { name: 'Add a language' }));
        await userEvent.type(await screen.findByLabelText(/Code/), 'fr');
        await userEvent.type(screen.getByLabelText(/Name in English/), 'French');
        await userEvent.type(screen.getByLabelText(/Name in the language itself/), 'Français');

        expect(screen.getByRole('checkbox', { name: /Serve it immediately/ })).not.toBeChecked();

        await userEvent.click(screen.getByRole('button', { name: 'Add language' }));

        await expect.poll(() => bodies.length).toBe(1);
        expect(bodies[0]).toMatchObject({ code: 'fr', is_active: false });
    });

    it('serves a new language at once only when that is chosen', async () => {
        const bodies: Array<Record<string, unknown>> = [];

        server.use(
            http.post('*/api/v1/admin/languages', async ({ request }) => {
                bodies.push((await request.json()) as Record<string, unknown>);

                return HttpResponse.json(
                    { success: true, message: 'created', data: { ...FRENCH, is_active: true } },
                    { status: 201 },
                );
            }),
        );

        renderScreen();

        await userEvent.click(await screen.findByRole('button', { name: 'Add a language' }));
        await userEvent.type(await screen.findByLabelText(/Code/), 'fr');
        await userEvent.type(screen.getByLabelText(/Name in English/), 'French');
        await userEvent.type(screen.getByLabelText(/Name in the language itself/), 'Français');
        await userEvent.click(screen.getByRole('checkbox', { name: /Serve it immediately/ }));
        await userEvent.click(screen.getByRole('button', { name: 'Add language' }));

        await expect.poll(() => bodies.length).toBe(1);
        expect(bodies[0]).toMatchObject({ is_active: true });
    });

    it('opens the missing entries when translating by hand', async () => {
        renderScreen();

        await userEvent.click(await screen.findByText('Arabic'));
        await userEvent.click(screen.getByRole('button', { name: 'Translate manually' }));

        expect(await screen.findByTestId('landed')).toHaveTextContent(
            '/translations?target=ar&state=missing',
        );
    });

    it('asks AI for what is missing, and says nothing was saved', async () => {
        const asked: Array<Record<string, unknown>> = [];

        server.use(
            http.post('*/api/v1/admin/translations/suggestions', async ({ request }) => {
                asked.push((await request.json()) as Record<string, unknown>);

                return HttpResponse.json({
                    success: true,
                    message: 'queued',
                    data: { queued: 12, skipped: 3 },
                });
            }),
        );

        renderScreen();

        await userEvent.click(await screen.findByText('Arabic'));
        await userEvent.click(await screen.findByRole('button', { name: 'Translate with AI' }));

        expect(await screen.findByText(/Asked for 12; skipped 3/)).toBeInTheDocument();
        expect(asked).toEqual([{ locale: 'ar' }]);
    });

    it('disables translating with AI, with the reason, when no provider is configured', async () => {
        renderScreen(undefined, undefined, overview({ available: false, may_use: true }));

        await userEvent.click(await screen.findByText('Arabic'));

        expect(screen.getByRole('button', { name: 'Translate with AI' })).toBeDisabled();
        expect(screen.getByText(/AI provider not configured/)).toBeInTheDocument();
        // The manual path is untouched.
        expect(screen.getByRole('button', { name: 'Translate manually' })).toBeEnabled();
    });

    it('shows AI progress by the states the platform stores', async () => {
        renderScreen(
            undefined,
            undefined,
            overview(undefined, [
                {
                    code: 'en',
                    coverage: { total: 10, translated: 10 },
                    suggestions: NO_SUGGESTIONS,
                },
                {
                    code: 'ar',
                    coverage: { total: 10, translated: 4 },
                    suggestions: { pending: 0, ready: 3, failed: 1, accepted: 4, dismissed: 0 },
                },
            ]),
        );

        await userEvent.click(await screen.findByText('Arabic'));

        expect(screen.getByText('Ready for review')).toBeInTheDocument();
        expect(
            screen.getByText('Suggestions are not translations until somebody accepts them.'),
        ).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Review suggestions' })).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Retry failed' })).toBeInTheDocument();
    });

    it('offers no translation workflow for the source language', async () => {
        renderScreen();

        const [english] = await screen.findAllByText('English');
        await userEvent.click(english as HTMLElement);

        expect(
            screen.queryByRole('button', { name: 'Translate manually' }),
        ).not.toBeInTheDocument();
        expect(screen.getByText(/Everything is translated from this language/)).toBeInTheDocument();
    });
});

describe('the interface language switcher', () => {
    function renderSwitcher(publicRows: ReturnType<typeof language>[]) {
        server.use(
            HEALTH,
            http.get('*/api/v1/languages', () =>
                HttpResponse.json({ success: true, data: publicRows }),
            ),
        );

        return render(
            <AppProviders>
                <LocaleControl />
            </AppProviders>,
        );
    }

    /**
     * Open the switcher and return its panel.
     *
     * The choices are behind a menu rather than laid out as segments — a list that
     * comes from the platform's language table cannot be a permanent row of buttons.
     * What is asserted below is unchanged: which languages the platform's answer puts
     * in front of an operator.
     */
    async function openSwitcher() {
        await userEvent.click(await screen.findByRole('button', { name: 'Language' }));

        return screen.getByRole('menu', { name: 'Language' });
    }

    it('offers the languages the platform serves, named as they name themselves', async () => {
        renderSwitcher([language(), ARABIC]);

        const panel = await openSwitcher();

        expect(within(panel).getByRole('menuitemradio', { name: /English/ })).toBeInTheDocument();
        expect(within(panel).getByRole('menuitemradio', { name: /العربية/ })).toBeInTheDocument();
    });

    it('drops a language the platform has deactivated rather than offering it', async () => {
        // Arabic is absent from the public list, which is what deactivating it does.
        renderSwitcher([language()]);

        // One language left is not a choice, so it is stated rather than offered.
        expect(await screen.findByText('Interface language: English')).toBeInTheDocument();
        expect(screen.queryByRole('button', { name: 'Language' })).not.toBeInTheDocument();
    });

    it('does not offer a language the console ships no catalogue for', async () => {
        renderSwitcher([
            language(),
            ARABIC,
            language({ id: 'lang-ku', code: 'ku', name: 'Kurdish', native_name: 'کوردی' }),
        ]);

        const panel = await openSwitcher();

        expect(within(panel).getByRole('menuitemradio', { name: /English/ })).toBeInTheDocument();
        // Offered by the platform, and this bundle has no catalogue for it: switching
        // would render an interface of raw keys.
        expect(
            within(panel).queryByRole('menuitemradio', { name: /کوردی/ }),
        ).not.toBeInTheDocument();
    });
});

/** The last request of a method the suite has seen, once one has arrived. */
async function waitForRequest(method: string): Promise<Request> {
    for (let attempt = 0; attempt < 50; attempt += 1) {
        const match = observed.findLast((request) => request.method === method);

        if (match !== undefined) {
            return match;
        }

        await new Promise((resolve) => setTimeout(resolve, 10));
    }

    throw new Error(`No ${method} request was sent.`);
}
