import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { HttpResponse, http } from 'msw';
import { MemoryRouter } from 'react-router';
import { afterEach, describe, expect, it, vi } from 'vitest';

import { AppProviders } from '@/app/AppProviders';
import { AuthGate } from '@/auth/AuthGate';
import { RolesScreen } from '@/screens/RolesScreen';
import { UsersScreen } from '@/screens/UsersScreen';
import { observed, server } from '@/test/server';

import '@/i18n';

/**
 * Access management, against the operations the platform actually has.
 *
 * Every assertion here corresponds to a real endpoint. There is no search parameter,
 * no pagination and no "reset password" because the API has none of them, and the
 * point of these tests is as much what is absent as what is present.
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

function account(over: Record<string, unknown> = {}) {
    return {
        id: '01hzzuser',
        name: 'Sami Odeh',
        email: 'sami@example.test',
        account_type: 'user',
        account_type_label: 'User',
        is_active: true,
        phone: null,
        email_verified: true,
        email_verified_at: '2026-01-01T00:00:00+00:00',
        mfa_enrolled: false,
        roles: [],
        permissions: [],
        ...over,
    };
}

const ROLES = http.get('*/api/v1/admin/roles', () =>
    HttpResponse.json({
        success: true,
        data: [
            {
                id: 1,
                name: 'administrator',
                name_label: 'Administrator',
                permissions: ['settings.view', 'users.view'],
            },
            { id: 2, name: 'editor', name_label: 'Editor', permissions: ['settings.view'] },
        ],
    }),
);

const PERMISSIONS = http.get('*/api/v1/admin/permissions', () =>
    HttpResponse.json({
        success: true,
        data: {
            settings: [
                { key: 'settings.view', label: 'View settings' },
                { key: 'settings.update', label: 'Change settings' },
            ],
            user: [{ key: 'users.view', label: 'View accounts' }],
        },
    }),
);

/**
 * jsdom applies no CSS, so a layout that switches structure has to switch on
 * `matchMedia` for a test to see either half of it. Stubbing it here is what lets the
 * table and the record list both be asserted against — and lets the duplication check
 * below mean something.
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

function renderScreen(node: React.ReactNode, permissions: string[], accounts = [account()]) {
    server.use(
        LANGUAGES,
        HEALTH,
        ROLES,
        PERMISSIONS,
        http.get('*/api/v1/admin/users', () =>
            HttpResponse.json({ success: true, data: accounts }),
        ),
        http.get('*/api/v1/admin/users/:id', ({ params }) =>
            HttpResponse.json({
                success: true,
                data: accounts.find((a) => a.id === params['id']) ?? accounts[0],
            }),
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
                    permissions,
                },
            }),
        ),
    );

    return render(
        <MemoryRouter>
            <AppProviders>
                <AuthGate>{node}</AuthGate>
            </AppProviders>
        </MemoryRouter>,
    );
}

describe('the users console', () => {
    it.each([
        ['a wide viewport', true],
        ['a narrow one', false],
    ])('shows identity and security state the platform publishes on %s', async (_name, wide) => {
        viewport(wide);

        renderScreen(
            <UsersScreen />,
            ['users.view'],
            [account({ email_verified: false, mfa_enrolled: true, roles: ['editor'] })],
        );

        // Exactly one of it. Rendering a table and a record list together and hiding
        // one with CSS reads the whole console twice to a screen reader, which is the
        // defect this asserts against rather than a styling preference.
        expect(await screen.findByText('Sami Odeh')).toBeInTheDocument();

        expect(screen.getByText('Unverified')).toBeInTheDocument();
        expect(screen.getByText('Enrolled')).toBeInTheDocument();
        // The narrow record carries the roles too; a smaller screen is not a reason to
        // answer a different question.
        expect(screen.getByText('editor')).toBeInTheDocument();
    });

    it('says the filtering is done here, because the API takes no query', async () => {
        renderScreen(
            <UsersScreen />,
            ['users.view'],
            [account(), account({ id: '2', name: 'Lina Barakat', email: 'lina@example.test' })],
        );

        await screen.findByText('Sami Odeh');

        expect(
            screen.getByText(/Filtered in the browser across all 2 accounts/),
        ).toBeInTheDocument();

        await userEvent.type(screen.getByLabelText('Filter accounts'), 'lina');

        expect(screen.queryByText('Sami Odeh')).not.toBeInTheDocument();
        expect(screen.getByText('Lina Barakat')).toBeInTheDocument();

        // No request was made for the filter. Pretending otherwise is the failure
        // this is guarding against.
        const listCalls = observed.filter((request) => request.url.includes('/admin/users'));
        expect(listCalls.every((request) => !request.url.includes('search'))).toBe(true);
    });

    it('offers promotion only to an account that may perform it', async () => {
        renderScreen(<UsersScreen />, ['users.view']);

        await userEvent.click(await screen.findByText('Sami Odeh'));

        expect(await screen.findByText('Identity')).toBeInTheDocument();
        // `users.update` is what the endpoint requires. Without it the control is not
        // offered — and the API would refuse it regardless, which is the real gate.
        expect(
            screen.queryByRole('button', { name: 'Make administrator' }),
        ).not.toBeInTheDocument();
        expect(
            screen.getByText('Changing roles needs the roles.update permission.'),
        ).toBeInTheDocument();
    });

    it('sends the whole role set, because that is what the endpoint takes', async () => {
        let sent: unknown = null;

        server.use(
            http.put('*/api/v1/admin/users/:id/roles', async ({ request }) => {
                sent = await request.json();

                return HttpResponse.json({ success: true, data: null });
            }),
        );

        renderScreen(<UsersScreen />, ['users.view', 'roles.update']);

        await userEvent.click(await screen.findByText('Sami Odeh'));
        await userEvent.click(await screen.findByRole('checkbox', { name: /Administrator/ }));
        await userEvent.click(screen.getByRole('button', { name: 'Save roles' }));

        // A full set, not a delta. Sending a delta would mean this client deciding
        // what the result should be.
        await expect.poll(() => sent).toEqual({ roles: ['administrator'] });
    });
});

describe('the roles surface', () => {
    it('groups permissions the way the API groups them, showing held and withheld', async () => {
        renderScreen(<RolesScreen />, ['roles.view', 'permissions.view']);

        expect(
            await screen.findByRole('heading', { level: 2, name: 'Administrator' }),
        ).toBeInTheDocument();

        // The module headings are the platform's own grouping.
        expect(screen.getByText('settings')).toBeInTheDocument();
        expect(screen.getByText('user')).toBeInTheDocument();

        // A withheld permission is still rendered — reading down a module has to tell
        // an operator what was granted *and* what was not.
        expect(screen.getByText('Change settings')).toBeInTheDocument();
        expect(screen.getByText('View settings')).toBeInTheDocument();
    });

    it('does not ask for the catalogue when the account may not read it', async () => {
        renderScreen(<RolesScreen />, ['roles.view']);

        expect(
            await screen.findByRole('heading', { level: 2, name: 'Administrator' }),
        ).toBeInTheDocument();
        expect(
            screen.getByText(
                'Seeing the permission catalogue needs the permissions.view permission.',
            ),
        ).toBeInTheDocument();

        // A request certain to be refused is never sent.
        expect(observed.some((request) => request.url.includes('/admin/permissions'))).toBe(false);
    });

    it('switches the inspected role', async () => {
        renderScreen(<RolesScreen />, ['roles.view', 'permissions.view']);

        await userEvent.click(await screen.findByRole('button', { name: /Editor/ }));

        const heading = screen.getByRole('heading', { level: 2 });
        expect(within(heading).getByText('Editor')).toBeInTheDocument();
    });
});
