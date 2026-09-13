import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { HttpResponse, http } from 'msw';
import { MemoryRouter } from 'react-router';
import { beforeEach, describe, expect, it } from 'vitest';

import { App } from '@/app/App';
import { AppProviders } from '@/app/AppProviders';
import { AuthGate } from '@/auth/AuthGate';
import { server } from '@/test/server';

import '@/i18n';

/**
 * The division the shell is organised around, asserted through the rendered console
 * rather than through the registry.
 *
 * The navigation answers where to go. The top bar answers who is signed in and how
 * they want this read. These fail if the two ever mix again — which they had: the
 * account's name and address sat loose in the chrome, sign-out was a bare button
 * beside the preference switches, and every theme, density and language option was its
 * own permanently visible button.
 */

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
            {
                code: 'ar',
                name: 'Arabic',
                native_name: 'العربية',
                direction: 'rtl',
                is_default: false,
            },
        ],
    }),
);

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

/** Every permission the registry declares, so both sections render. */
const EVERY_PERMISSION = [
    'settings.view',
    'users.view',
    'roles.view',
    'permissions.view',
    'integrations.view',
    'media.view',
    'audit.view',
];

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
    permissions: EVERY_PERMISSION,
};

/**
 * Render the console and return the chrome across the top.
 *
 * Found through the account trigger rather than by landmark role: waiting for that
 * button is how this knows the session resolved and the bar is complete, and walking
 * up from it asserts on the way past that the account menu really is in the top bar
 * rather than somewhere else on the page.
 */
async function openConsole(user: Record<string, unknown> = ADMIN) {
    server.use(
        LANGUAGES,
        HEALTH,
        http.get('*/api/v1/auth/me', () => HttpResponse.json({ success: true, data: user })),
    );

    render(
        <MemoryRouter initialEntries={['/dashboard']}>
            <AppProviders>
                <AuthGate>
                    <App />
                </AuthGate>
            </AppProviders>
        </MemoryRouter>,
    );

    const account = await screen.findByRole('button', { name: 'Account' }, { timeout: 5000 });
    const bar = account.closest('header');

    if (bar === null) {
        throw new Error('the account menu is not in the top bar');
    }

    return bar;
}

/** The navigation panel, by the name it announces itself with. */
function navigation() {
    return screen.getByRole('navigation', { name: 'Modules' });
}

beforeEach(() => {
    localStorage.clear();
});

describe('the top bar', () => {
    it('holds three menus: language, appearance and the current account', async () => {
        const bar = await openConsole();

        for (const name of ['Language', 'Appearance', 'Account']) {
            expect(within(bar).getByRole('button', { name })).toHaveAttribute(
                'aria-haspopup',
                'menu',
            );
        }
    });

    it('has no loose preference buttons left in the chrome', async () => {
        const bar = await openConsole();

        // What this replaced. Each was a permanently visible button, and the language
        // row grew every time the platform activated a language.
        for (const gone of ['Light', 'Dark', 'System', 'Compact', 'Comfortable', 'Spacious']) {
            expect(within(bar).queryByRole('radio', { name: gone })).not.toBeInTheDocument();
        }
    });

    it('does not leave sign-out one mis-click away from the preference switches', async () => {
        const bar = await openConsole();

        expect(within(bar).queryByRole('button', { name: 'Sign out' })).not.toBeInTheDocument();

        await userEvent.click(within(bar).getByRole('button', { name: 'Account' }));

        const panel = within(bar).getByRole('menu', { name: 'Account' });
        expect(within(panel).getByRole('menuitem', { name: /Sign out/ })).toBeInTheDocument();
    });

    it('keeps the account address inside the menu rather than beside it', async () => {
        const bar = await openConsole();

        // The address used to sit in the chrome at all times. The name stays on the
        // trigger, because a console should say whose session it is.
        expect(within(bar).queryByText('fakhri@example.test')).not.toBeInTheDocument();

        await userEvent.click(within(bar).getByRole('button', { name: 'Account' }));

        const panel = within(bar).getByRole('menu', { name: 'Account' });
        expect(within(panel).getByText('Fakhri Al-Najjar')).toBeInTheDocument();
        expect(within(panel).getByText('fakhri@example.test')).toBeInTheDocument();
        expect(within(panel).getByText('Email verified')).toBeInTheDocument();
    });

    it('says when the address on the session has not been confirmed', async () => {
        const bar = await openConsole({
            ...ADMIN,
            email_verified: false,
            email_verified_at: null,
        });

        await userEvent.click(within(bar).getByRole('button', { name: 'Account' }));

        const panel = within(bar).getByRole('menu', { name: 'Account' });
        expect(within(panel).getByText('Email not verified')).toBeInTheDocument();
    });

    it('closes an open menu when the pointer goes elsewhere', async () => {
        const bar = await openConsole();

        await userEvent.click(within(bar).getByRole('button', { name: 'Account' }));
        expect(within(bar).getByRole('menu', { name: 'Account' })).toBeInTheDocument();

        await userEvent.click(screen.getByRole('main'));

        expect(within(bar).queryByRole('menu', { name: 'Account' })).not.toBeInTheDocument();
    });

    it('reaches the account page from the account menu, not the navigation', async () => {
        const bar = await openConsole();

        await userEvent.click(within(bar).getByRole('button', { name: 'Account' }));

        // A page about the viewer, from the viewer's menu. The navigation keeps
        // answering "where do I go in this system" and never lists it.
        const panel = within(bar).getByRole('menu', { name: 'Account' });
        expect(within(panel).getByRole('menuitem', { name: /Your account/ })).toBeInTheDocument();
        expect(
            within(navigation()).queryByRole('link', { name: /Your account/ }),
        ).not.toBeInTheDocument();
    });

    it('opens one menu at a time, so two panels never overlap', async () => {
        const bar = await openConsole();

        await userEvent.click(within(bar).getByRole('button', { name: 'Account' }));
        await userEvent.click(within(bar).getByRole('button', { name: 'Appearance' }));

        expect(within(bar).getByRole('menu', { name: 'Appearance' })).toBeInTheDocument();
        expect(within(bar).queryByRole('menu', { name: 'Account' })).not.toBeInTheDocument();
    });
});

describe('the navigation, as the console renders it', () => {
    it('offers the two sections, and puts accounts in the one that is about accounts', async () => {
        await openConsole();

        const nav = navigation();

        // Who may act is read before how the platform behaves, so the access section

        // comes first. The only buttons in the navigation are the two section toggles.

        expect(
            within(nav)
                .getAllByRole('button')

                .map((button) => button.getAttribute('aria-label')),
        ).toEqual(['Expand Users & permissions', 'Expand Settings']);

        // Sections are disclosures, not links: there is no screen at either.
        await userEvent.click(
            within(nav).getByRole('button', { name: 'Expand Users & permissions' }),
        );

        for (const name of ['Users', 'Roles', 'Permissions']) {
            expect(within(nav).getByRole('link', { name })).toBeInTheDocument();
        }

        await userEvent.click(
            within(nav).getByRole('button', { name: 'Collapse Users & permissions' }),
        );
        await userEvent.click(within(nav).getByRole('button', { name: 'Expand Settings' }));

        // Settings holds what configures the platform, and none of the three above.
        for (const name of [
            'General settings',
            'Languages',
            'Media',
            'Notifications',
            'Integrations',
            'AI',
            'Translations',
            'Operations',
        ]) {
            expect(within(nav).getByRole('link', { name })).toBeInTheDocument();
        }

        for (const name of ['Users', 'Roles', 'Permissions']) {
            expect(within(nav).queryByRole('link', { name })).not.toBeInTheDocument();
        }
    });

    it('carries nothing about the signed-in account', async () => {
        await openConsole();

        const nav = navigation();

        // An account is not somewhere you go, so there is no item for it here and no
        // way to end the session from the navigation.
        expect(within(nav).queryByRole('link', { name: /account/i })).not.toBeInTheDocument();
        expect(within(nav).queryByRole('button', { name: /account/i })).not.toBeInTheDocument();
        expect(within(nav).queryByText('Sign out')).not.toBeInTheDocument();
        expect(within(nav).queryByText('fakhri@example.test')).not.toBeInTheDocument();
    });

    it('shows a restricted operator their sections and no empty headings', async () => {
        await openConsole({ ...ADMIN, permissions: ['settings.view'] });

        const nav = navigation();

        expect(within(nav).getByRole('button', { name: 'Expand Settings' })).toBeInTheDocument();
        expect(
            within(nav).queryByRole('button', { name: /Users & permissions/ }),
        ).not.toBeInTheDocument();
    });
});
