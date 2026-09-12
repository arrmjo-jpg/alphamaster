import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { HttpResponse, http } from 'msw';
import { MemoryRouter } from 'react-router';
import { describe, expect, it } from 'vitest';

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

function renderScreen(
    rows: ReturnType<typeof language>[] = [language(), ARABIC],
    publicRows: ReturnType<typeof language>[] = rows.filter((row) => row.is_active),
) {
    server.use(
        HEALTH,
        http.get('*/api/v1/languages', () =>
            HttpResponse.json({ success: true, data: publicRows }),
        ),
        http.get('*/api/v1/admin/languages', () =>
            HttpResponse.json({ success: true, data: rows }),
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
        <MemoryRouter>
            <AppProviders>
                <AuthGate>
                    <LanguagesScreen />
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

    it('offers the languages the platform serves, named as they name themselves', async () => {
        renderSwitcher([language(), ARABIC]);

        expect(await screen.findByRole('radio', { name: 'English' })).toBeInTheDocument();
        expect(screen.getByRole('radio', { name: 'العربية' })).toBeInTheDocument();
    });

    it('drops a language the platform has deactivated rather than offering it', async () => {
        // Arabic is absent from the public list, which is what deactivating it does.
        renderSwitcher([language()]);

        // One language left is not a choice, so it is stated rather than offered.
        expect(await screen.findByText('Interface language: English')).toBeInTheDocument();
        expect(screen.queryByRole('radio', { name: 'العربية' })).not.toBeInTheDocument();
    });

    it('does not offer a language the console ships no catalogue for', async () => {
        renderSwitcher([
            language(),
            ARABIC,
            language({ id: 'lang-ku', code: 'ku', name: 'Kurdish', native_name: 'کوردی' }),
        ]);

        expect(await screen.findByRole('radio', { name: 'English' })).toBeInTheDocument();
        expect(screen.queryByRole('radio', { name: 'کوردی' })).not.toBeInTheDocument();
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
