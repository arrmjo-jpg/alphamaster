import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { HttpResponse, http } from 'msw';
import { MemoryRouter } from 'react-router';
import { beforeEach, describe, expect, it } from 'vitest';

import { App } from '@/app/App';
import { AppProviders } from '@/app/AppProviders';
import { AuthGate } from '@/auth/AuthGate';
import { initialsOf } from '@/shell/initials';
import { server } from '@/test/server';

import '@/i18n';

/**
 * The top bar's "Go to…" search.
 *
 * What it must never be is a search box that pretends: it matches the console's own
 * destinations, drawn from the same permission-filtered registry the navigation
 * renders, and nothing else. So the assertions below are about reach as much as
 * about matching — it must offer everything the viewer may open, and it must not
 * offer one thing more.
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

// Whatever screen a jump lands on may ask for its own data. This test is about the
// jump, so every administrative read answers with an empty list rather than being
// left unhandled.
const ANY_ADMIN_READ = http.get('*/api/v1/admin/*', () =>
    HttpResponse.json({ success: true, data: [], meta: { total: 0 } }),
);

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

async function openConsole(user: Record<string, unknown> = ADMIN) {
    server.use(
        LANGUAGES,
        HEALTH,
        ANY_ADMIN_READ,
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

    return screen.findByRole('button', { name: 'Go to a page' }, { timeout: 5000 });
}

function palette() {
    return screen.getByRole('dialog', { name: 'Go to' });
}

beforeEach(() => {
    localStorage.clear();
});

describe('going to a page', () => {
    it('sits in the top bar and says it opens a dialog', async () => {
        const trigger = await openConsole();

        expect(trigger.closest('header')).not.toBeNull();
        expect(trigger).toHaveAttribute('aria-haspopup', 'dialog');
    });

    it('opens from Ctrl+K anywhere, with the caret already in the field', async () => {
        await openConsole();

        await userEvent.keyboard('{Control>}k{/Control}');

        expect(within(palette()).getByRole('combobox', { name: 'Go to' })).toHaveFocus();
    });

    it('offers every destination the viewer may open, and filters as they type', async () => {
        const trigger = await openConsole();
        await userEvent.click(trigger);

        // Thirteen destinations, and this account may open all of them — the account
        // page among them: out of the navigation, but still somewhere a person can go.
        expect(within(palette()).getAllByRole('option')).toHaveLength(13);

        await userEvent.type(within(palette()).getByRole('combobox'), 'rol');

        const options = within(palette()).getAllByRole('option');
        expect(options).toHaveLength(1);
        expect(options[0]).toHaveTextContent('Roles');
        // The section name travels with it, so two modules that share a word are
        // still told apart.
        expect(options[0]).toHaveTextContent('Users & permissions');
    });

    it('goes there on Enter, and the navigation agrees about where you are', async () => {
        const trigger = await openConsole();
        await userEvent.click(trigger);

        await userEvent.type(within(palette()).getByRole('combobox'), 'languages');
        await userEvent.keyboard('{Enter}');

        expect(screen.queryByRole('dialog', { name: 'Go to' })).not.toBeInTheDocument();

        const nav = screen.getByRole('navigation', { name: 'Modules' });
        expect(await within(nav).findByRole('link', { name: 'Languages' })).toHaveAttribute(
            'aria-current',
            'page',
        );
    });

    it('moves the selection with the arrow keys without moving the caret', async () => {
        const trigger = await openConsole();
        await userEvent.click(trigger);

        const field = within(palette()).getByRole('combobox');
        const first = within(palette()).getAllByRole('option')[0];
        expect(first).toHaveAttribute('aria-selected', 'true');

        await userEvent.keyboard('{ArrowDown}');

        const second = within(palette()).getAllByRole('option')[1];
        expect(second).toHaveAttribute('aria-selected', 'true');
        expect(field).toHaveAttribute('aria-activedescendant', second?.id);
        expect(field).toHaveFocus();
    });

    it('closes on Escape and gives focus back to the trigger', async () => {
        const trigger = await openConsole();
        await userEvent.click(trigger);

        await userEvent.keyboard('{Escape}');

        expect(screen.queryByRole('dialog', { name: 'Go to' })).not.toBeInTheDocument();
        expect(trigger).toHaveFocus();
    });

    it('never offers a destination the viewer may not open', async () => {
        // An operator who may read settings and nothing about accounts. The search
        // is drawn from the same filtered registry as the navigation, so it cannot
        // become a side door to a module the sidebar correctly hides.
        const trigger = await openConsole({ ...ADMIN, permissions: ['settings.view'] });
        await userEvent.click(trigger);

        await userEvent.type(within(palette()).getByRole('combobox'), 'users');

        expect(within(palette()).queryAllByRole('option')).toHaveLength(0);
        expect(within(palette()).getByText('No page matches that.')).toBeInTheDocument();
    });
});

describe('the letters an account is recognised by', () => {
    it('takes two initials from a name written with capitals', () => {
        expect(initialsOf('Fakhri Al-Najjar')).toBe('FA');
        expect(initialsOf('nadia haddad')).toBe('NH');
    });

    it('takes one letter from a name in a script without capitals', () => {
        // Two isolated Arabic letters from separate words read as neither initials
        // nor a word; the first letter alone is how an Arabic interface marks a person.
        expect(initialsOf('فخري النجار')).toBe('ف');
    });

    it('copes with a single name and with nothing at all', () => {
        expect(initialsOf('Nadia')).toBe('N');
        expect(initialsOf('   ')).toBe('?');
    });
});
