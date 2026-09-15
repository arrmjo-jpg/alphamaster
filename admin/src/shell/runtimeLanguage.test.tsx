import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { HttpResponse, http } from 'msw';
import { MemoryRouter } from 'react-router';
import { afterEach, describe, expect, it } from 'vitest';

import { App } from '@/app/App';
import { AppProviders } from '@/app/AppProviders';
import { AuthGate } from '@/auth/AuthGate';
import i18n, { CATALOGUE_LOCALE } from '@/i18n';
import { observed, server } from '@/test/server';

/**
 * A language added in Language Management, end to end through the console (ADR 0049).
 *
 * The language below exists in no file and no line of this bundle. The platform lists it; the
 * top bar offers it; choosing it turns the document around by the direction the platform gave;
 * the sidebar reads the wording the platform serves for it; and whatever it has not translated
 * reads in English. After a refresh the console opens in it. Nothing here adds a locale.
 */

const INVENTED = {
    code: 'qzx',
    name: 'Invented',
    native_name: 'Erfunden',
    direction: 'rtl',
    is_default: false,
};

const LANGUAGES = http.get('*/api/v1/languages', () =>
    HttpResponse.json({
        success: true,
        data: [
            {
                code: 'en',
                name: 'English',
                native_name: 'English',
                direction: 'ltr',
                is_default: true,
            },
            INVENTED,
        ],
    }),
);

/** What the platform serves for the language once some of its interface is translated. */
const CATALOGUE = http.get('*/api/v1/interface/console/qzx', () =>
    HttpResponse.json({
        success: true,
        data: {
            modules: { dashboard: 'Übersicht-QZX', settings: 'Einstellungen-QZX' },
            language: { label: 'Sprache-QZX' },
        },
    }),
);

const HEALTH = http.get('*/api/v1/health', () =>
    HttpResponse.json({
        success: true,
        data: {
            status: 'healthy',
            timestamp: '2026-09-14T10:00:00+00:00',
            framework: 'Laravel 13',
        },
    }),
);

const ADMIN = {
    id: '01hzz',
    name: 'Fakhri Al-Najjar',
    email: 'fakhri@example.test',
    account_type: 'admin',
    is_active: true,
    email_verified: true,
    email_verified_at: '2026-01-01T00:00:00+00:00',
    abilities: ['admin:access'],
    roles: ['administrator'],
    permissions: ['settings.view', 'users.view'],
};

function renderConsole() {
    server.use(
        LANGUAGES,
        CATALOGUE,
        HEALTH,
        http.get('*/api/v1/auth/me', () => HttpResponse.json({ success: true, data: ADMIN })),
    );

    return render(
        <MemoryRouter initialEntries={['/dashboard']}>
            <AppProviders>
                <AuthGate>
                    <App />
                </AuthGate>
            </AppProviders>
        </MemoryRouter>,
    );
}

afterEach(async () => {
    await i18n.changeLanguage(CATALOGUE_LOCALE);
    document.documentElement.dir = 'ltr';
});

describe('a language added at runtime', () => {
    it('is offered in the top bar, and choosing it reads the console in it', async () => {
        renderConsole();

        await screen.findByRole('button', { name: 'Account' }, { timeout: 5000 });

        await userEvent.click(await screen.findByRole('button', { name: 'Language' }));
        const menu = screen.getByRole('menu', { name: 'Language' });

        await userEvent.click(within(menu).getByRole('menuitemradio', { name: /Erfunden/ }));

        const modules = await screen.findByRole('navigation', { name: 'Modules' });

        // Translated where the platform has wording for it…
        expect(await within(modules).findByText('Übersicht-QZX')).toBeInTheDocument();
        // …and English, key by key, where it does not yet.
        expect(within(modules).getByText('Users')).toBeInTheDocument();

        expect(document.documentElement.lang).toBe('qzx');
        expect(document.documentElement.dir).toBe('rtl');
        expect(
            observed.some((request) => request.url.endsWith('/api/v1/interface/console/qzx')),
        ).toBe(true);
    });

    it('is the language the console opens in after a refresh', async () => {
        localStorage.setItem('alphamaster.locale', 'qzx');

        renderConsole();

        const modules = await screen.findByRole(
            'navigation',
            { name: 'Modules' },
            { timeout: 5000 },
        );

        expect(await within(modules).findByText('Übersicht-QZX')).toBeInTheDocument();
        expect(document.documentElement.dir).toBe('rtl');
    });
});
