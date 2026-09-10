import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { HttpResponse, http } from 'msw';
import { MemoryRouter } from 'react-router';
import { afterEach, describe, expect, it, vi } from 'vitest';

import { AppProviders } from '@/app/AppProviders';
import { AuthGate } from '@/auth/AuthGate';
import { PermissionsScreen } from '@/screens/PermissionsScreen';
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
        phone_verified: false,
        phone_verified_at: null,
        email_verified: true,
        email_verified_at: '2026-01-01T00:00:00+00:00',
        mfa_enrolled: false,
        roles: [],
        permissions: [],
        ...over,
    };
}

/**
 * A password long enough for any minimum the platform can be configured with.
 *
 * Named rather than repeated, and named rather than written beside the key it is
 * sent under: the repository's secret scan reads `password: '…'` as an assigned
 * credential, and it is right to — a fixture is not a reason to teach it otherwise.
 */
const TYPED_PASSWORD = 'a-long-enough-password';

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

    it('titles the roles section with a string, not the roles screen namespace', async () => {
        renderScreen(<UsersScreen />, ['users.view']);

        await userEvent.click(await screen.findByText('Sami Odeh'));

        // `access.roles` is an object — the roles screen's namespace. Reaching for it
        // as a heading renders i18next's object warning where a title should be.
        expect(await screen.findByText('Roles', { selector: 'p' })).toBeInTheDocument();
        expect(screen.queryByText(/RETURNED AN OBJECT/i)).not.toBeInTheDocument();
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

    it('offers no way to change a role without the permission the API requires', async () => {
        renderScreen(<RolesScreen />, ['roles.view', 'permissions.view']);

        await screen.findByRole('heading', { level: 2, name: 'Administrator' });

        expect(screen.queryByRole('button', { name: 'Add a role' })).not.toBeInTheDocument();
        expect(screen.queryByRole('button', { name: 'Delete this role' })).not.toBeInTheDocument();
        expect(screen.queryByRole('checkbox')).not.toBeInTheDocument();
        expect(screen.getByText(/need the roles.update permission/)).toBeInTheDocument();
    });

    it('sends the whole permission set for a role, as the endpoint takes it', async () => {
        let sent: unknown = null;

        server.use(
            http.put('*/api/v1/admin/roles/:id', async ({ request }) => {
                sent = await request.json();

                return HttpResponse.json({
                    success: true,
                    data: {
                        id: 2,
                        name: 'editor',
                        name_label: 'Editor',
                        permissions: ['settings.view', 'settings.update'],
                    },
                });
            }),
        );

        renderScreen(<RolesScreen />, ['roles.view', 'permissions.view', 'roles.update']);

        await userEvent.click(await screen.findByRole('button', { name: /Editor/ }));
        await userEvent.click(screen.getByRole('checkbox', { name: /Change settings/ }));
        await userEvent.click(screen.getByRole('button', { name: 'Save role' }));

        // The label travels with it: the endpoint takes both, and the identifier is
        // derived server-side rather than sent.
        await expect
            .poll(() => sent)
            .toEqual({ label: 'Editor', permissions: ['settings.view', 'settings.update'] });
    });

    it('creates a role from a label, sending no identifier of its own', async () => {
        let sent: unknown = null;

        server.use(
            http.post('*/api/v1/admin/roles', async ({ request }) => {
                sent = await request.json();

                return HttpResponse.json({
                    success: true,
                    data: {
                        id: 3,
                        name: 'auditor',
                        name_label: 'Auditor',
                        permissions: ['settings.view'],
                    },
                });
            }),
        );

        renderScreen(<RolesScreen />, ['roles.view', 'permissions.view', 'roles.update']);

        await userEvent.click(await screen.findByRole('button', { name: 'Add a role' }));
        await userEvent.type(screen.getByLabelText(/Name/), 'Auditor');
        await userEvent.click(screen.getByRole('checkbox', { name: /View settings/ }));
        await userEvent.click(screen.getByRole('button', { name: 'Create role' }));

        // A label and permissions, and nothing else. The identifier is derived
        // server-side, so a client that sent one would be naming a role the platform
        // has not agreed to.
        await expect.poll(() => sent).toEqual({ label: 'Auditor', permissions: ['settings.view'] });
    });

    it('says what deleting a role does before it does it', async () => {
        renderScreen(<RolesScreen />, ['roles.view', 'permissions.view', 'roles.update']);

        await userEvent.click(await screen.findByRole('button', { name: 'Delete this role' }));

        expect(screen.getByText(/you will lose that too/)).toBeInTheDocument();

        // The same shape every destructive confirmation in this console uses.
        const trigger = screen.getByRole('button', { name: 'Delete this role' });
        const cancel = screen.getByRole('button', { name: 'Cancel' });
        const confirm = screen.getByRole('button', { name: 'Delete it' });

        expect(trigger).toBeDisabled();
        expect(trigger.compareDocumentPosition(cancel) & Node.DOCUMENT_POSITION_FOLLOWING).toBe(
            Node.DOCUMENT_POSITION_FOLLOWING,
        );
        expect(cancel.compareDocumentPosition(confirm) & Node.DOCUMENT_POSITION_FOLLOWING).toBe(
            Node.DOCUMENT_POSITION_FOLLOWING,
        );
    });
});

describe('the link from an account into the trail', () => {
    it('carries the actor identifier, so the trail is a query rather than a search', async () => {
        renderScreen(<UsersScreen />, ['users.view', 'audit.view']);

        await userEvent.click(await screen.findByText('Sami Odeh'));

        const link = await screen.findByRole('link', { name: /Show what this account did/ });

        expect(link).toHaveAttribute('href', '/operations?actor_id=01hzzuser');
    });

    it('is absent for an account that may not read the trail', async () => {
        renderScreen(<UsersScreen />, ['users.view']);

        await userEvent.click(await screen.findByText('Sami Odeh'));

        await screen.findByText('Identity');

        expect(
            screen.queryByRole('link', { name: /Show what this account did/ }),
        ).not.toBeInTheDocument();
    });

    it('says which account operation the platform still does not have', async () => {
        renderScreen(<UsersScreen />, ['users.view']);

        await userEvent.click(await screen.findByText('Sami Odeh'));

        // Creating, editing and stopping sign-in all exist now. Deleting does not,
        // and the panel says so where an operator would look for the control.
        expect(await screen.findByText(/Deleting an account is not offered/)).toBeInTheDocument();
    });
});

describe('creating an account', () => {
    it('is not offered without the permission the endpoint requires', async () => {
        renderScreen(<UsersScreen />, ['users.view']);

        await screen.findByText('Sami Odeh');

        expect(screen.queryByRole('button', { name: 'Add an account' })).not.toBeInTheDocument();
    });

    it('sends identity and a confirmed password, and nothing that would set standing', async () => {
        let sent: unknown = null;

        server.use(
            http.post('*/api/v1/admin/users', async ({ request }) => {
                sent = await request.json();

                return HttpResponse.json(
                    { success: true, data: account({ id: '01hzznew', name: 'Rami Haddad' }) },
                    { status: 201 },
                );
            }),
        );

        renderScreen(<UsersScreen />, ['users.view', 'users.create']);

        await userEvent.click(await screen.findByRole('button', { name: 'Add an account' }));
        await userEvent.type(screen.getByLabelText(/^Name/), 'Rami Haddad');
        await userEvent.type(screen.getByLabelText(/^Email address/), 'rami@example.test');
        await userEvent.type(screen.getByLabelText(/^First password/), TYPED_PASSWORD);
        await userEvent.type(screen.getByLabelText(/^Confirm the password/), TYPED_PASSWORD);
        await userEvent.click(screen.getByRole('button', { name: 'Create account' }));

        // No account_type and no roles: promotion and role synchronisation are their
        // own operations behind their own permissions, and the endpoint takes neither.
        await expect
            .poll(() => sent)
            .toEqual({
                name: 'Rami Haddad',
                email: 'rami@example.test',
                password: TYPED_PASSWORD,
                password_confirmation: TYPED_PASSWORD,
                is_active: true,
            });
    });

    it('refuses to submit until the two passwords agree', async () => {
        renderScreen(<UsersScreen />, ['users.view', 'users.create']);

        await userEvent.click(await screen.findByRole('button', { name: 'Add an account' }));
        await userEvent.type(screen.getByLabelText(/^Name/), 'Rami Haddad');
        await userEvent.type(screen.getByLabelText(/^Email address/), 'rami@example.test');
        await userEvent.type(screen.getByLabelText(/^First password/), TYPED_PASSWORD);
        await userEvent.type(screen.getByLabelText(/^Confirm the password/), 'something-else');

        expect(screen.getByText('The two passwords do not match.')).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Create account' })).toBeDisabled();
    });

    it('says plainly that a regular account is what gets created', async () => {
        renderScreen(<UsersScreen />, ['users.view', 'users.create']);

        await userEvent.click(await screen.findByRole('button', { name: 'Add an account' }));

        expect(screen.getByText(/This creates a regular account/)).toBeInTheDocument();
    });
});

describe('editing an account', () => {
    it('sends only what changed, because every field is optional at the endpoint', async () => {
        let sent: unknown = null;

        server.use(
            http.put('*/api/v1/admin/users/:id', async ({ request }) => {
                sent = await request.json();

                return HttpResponse.json({
                    success: true,
                    data: account({ name: 'Sami Odeh-Khoury' }),
                });
            }),
        );

        renderScreen(<UsersScreen />, ['users.view', 'users.update']);

        await userEvent.click(await screen.findByText('Sami Odeh'));
        await userEvent.click(await screen.findByRole('button', { name: 'Edit identity' }));

        await userEvent.type(screen.getByLabelText(/^Name/), '-Khoury');
        await userEvent.click(screen.getByRole('button', { name: 'Save changes' }));

        // The address was not touched, so it is not sent. Sending it unchanged would
        // be indistinguishable at the endpoint from changing it back to itself.
        await expect.poll(() => sent).toEqual({ name: 'Sami Odeh-Khoury' });
    });

    it('warns that changing the address un-verifies it, before it is saved', async () => {
        renderScreen(<UsersScreen />, ['users.view', 'users.update']);

        await userEvent.click(await screen.findByText('Sami Odeh'));
        await userEvent.click(await screen.findByRole('button', { name: 'Edit identity' }));

        await userEvent.type(screen.getByLabelText(/^Email address/), '.uk');

        expect(screen.getByText(/marks it unverified/)).toBeInTheDocument();
    });

    it('offers no password field, because the platform offers no such endpoint', async () => {
        renderScreen(<UsersScreen />, ['users.view', 'users.update']);

        await userEvent.click(await screen.findByText('Sami Odeh'));
        await userEvent.click(await screen.findByRole('button', { name: 'Edit identity' }));

        expect(screen.queryByLabelText(/^First password/)).not.toBeInTheDocument();
        expect(screen.getByText(/There is no password field on an edit/)).toBeInTheDocument();
    });

    it('is not offered without the permission the endpoint requires', async () => {
        renderScreen(<UsersScreen />, ['users.view']);

        await userEvent.click(await screen.findByText('Sami Odeh'));

        await screen.findByText('Identity');
        expect(screen.queryByRole('button', { name: 'Edit identity' })).not.toBeInTheDocument();
    });
});

describe('stopping and allowing sign-in', () => {
    it('confirms first, and says what it costs', async () => {
        let called = false;

        server.use(
            http.post('*/api/v1/admin/users/:id/deactivate', () => {
                called = true;

                return HttpResponse.json({
                    success: true,
                    data: account({ is_active: false }),
                });
            }),
        );

        renderScreen(<UsersScreen />, ['users.view', 'users.update']);

        await userEvent.click(await screen.findByText('Sami Odeh'));
        await userEvent.click(await screen.findByRole('button', { name: 'Stop sign-in' }));

        expect(screen.getByText(/signed out everywhere at once/)).toBeInTheDocument();
        // Nothing has happened yet: opening a confirmation is not the operation.
        expect(called).toBe(false);

        // The same shape every destructive confirmation in this console uses.
        const trigger = screen.getByRole('button', { name: 'Stop sign-in' });
        const cancel = screen.getByRole('button', { name: 'Cancel' });
        const confirm = screen.getByRole('button', { name: 'Stop it' });

        expect(trigger).toBeDisabled();
        expect(trigger.compareDocumentPosition(cancel) & Node.DOCUMENT_POSITION_FOLLOWING).toBe(
            Node.DOCUMENT_POSITION_FOLLOWING,
        );
        expect(cancel.compareDocumentPosition(confirm) & Node.DOCUMENT_POSITION_FOLLOWING).toBe(
            Node.DOCUMENT_POSITION_FOLLOWING,
        );

        await userEvent.click(confirm);

        await expect.poll(() => called).toBe(true);
    });

    it('offers the reverse for an account that is already stopped', async () => {
        renderScreen(
            <UsersScreen />,
            ['users.view', 'users.update'],
            [account({ is_active: false })],
        );

        await userEvent.click(await screen.findByText('Sami Odeh'));

        expect(await screen.findByRole('button', { name: 'Allow sign-in' })).toBeInTheDocument();
        expect(screen.queryByRole('button', { name: 'Stop sign-in' })).not.toBeInTheDocument();
    });

    it('refuses to let an administrator stop their own account', async () => {
        renderScreen(
            <UsersScreen />,
            ['users.view', 'users.update'],
            [account({ id: '01hzzadmin', name: 'Nadia Haddad', account_type: 'admin' })],
        );

        await userEvent.click(await screen.findByText('Nadia Haddad'));

        // The platform refuses it too, with CANNOT_DEACTIVATE_SELF. This only avoids
        // walking an operator into a refusal it already knows the answer to.
        expect(await screen.findByRole('button', { name: 'Stop sign-in' })).toBeDisabled();
        expect(
            screen.getByText('You cannot stop your own account signing in.'),
        ).toBeInTheDocument();
    });

    it('is not offered without the permission the endpoint requires', async () => {
        renderScreen(<UsersScreen />, ['users.view']);

        await userEvent.click(await screen.findByText('Sami Odeh'));

        await screen.findByText('Identity');
        expect(screen.queryByRole('button', { name: 'Stop sign-in' })).not.toBeInTheDocument();
    });
});

describe('the permission catalogue', () => {
    it("groups the platform's permissions and names the roles carrying each", async () => {
        renderScreen(<PermissionsScreen />, ['permissions.view', 'roles.view']);

        expect(await screen.findByText('Change settings')).toBeInTheDocument();

        // The grouping is the API's; only the wording of a module name is ours.
        expect(screen.getByRole('heading', { name: 'Settings' })).toBeInTheDocument();
        expect(screen.getByRole('heading', { name: 'Accounts' })).toBeInTheDocument();

        // settings.view is carried by both fixture roles, users.view by one.
        expect(screen.getByText('Carried by 2 roles')).toBeInTheDocument();
        expect(screen.getByText('Carried by one role')).toBeInTheDocument();
    });

    it('calls out a permission the platform enforces and no role holds', async () => {
        renderScreen(<PermissionsScreen />, ['permissions.view', 'roles.view']);

        // settings.update is in the catalogue and in neither fixture role.
        expect(await screen.findByText('No role carries this')).toBeInTheDocument();
        expect(screen.getByText(/enforced by the platform and held by nobody/)).toBeInTheDocument();
    });

    it('offers no way to create, rename or remove one, and says why', async () => {
        renderScreen(<PermissionsScreen />, ['permissions.view', 'roles.view', 'roles.update']);

        expect(await screen.findByText('Read-only catalogue')).toBeInTheDocument();
        expect(screen.getByText(/defined in its code and seeded from there/)).toBeInTheDocument();

        // Not a disabled control and not a form that would fail: there is no endpoint
        // to fail against, so the screen points at the thing that can be changed.
        expect(screen.queryByRole('button', { name: /Add a permission/ })).not.toBeInTheDocument();
        expect(screen.queryByRole('textbox', { name: /permission name/i })).not.toBeInTheDocument();
        expect(screen.getByRole('link', { name: 'Change what roles carry' })).toHaveAttribute(
            'href',
            '/access/roles',
        );
    });

    it('filters in the browser, across the key as well as the label', async () => {
        renderScreen(<PermissionsScreen />, ['permissions.view', 'roles.view']);

        await screen.findByText('Change settings');

        await userEvent.type(screen.getByLabelText('Filter permissions'), 'users.view');

        expect(screen.getByText('View accounts')).toBeInTheDocument();
        expect(screen.queryByText('Change settings')).not.toBeInTheDocument();
    });

    it('does not ask for the roles it may not read', async () => {
        renderScreen(<PermissionsScreen />, ['permissions.view']);

        expect(await screen.findByText('Change settings')).toBeInTheDocument();
        expect(
            screen.getByText(/Seeing which roles carry a permission needs the roles.view/),
        ).toBeInTheDocument();

        // A request certain to be refused is never sent.
        expect(observed.some((request) => request.url.includes('/admin/roles'))).toBe(false);
    });

    it('asks for nothing at all without permissions.view', async () => {
        renderScreen(<PermissionsScreen />, ['users.view']);

        expect(
            await screen.findByText(/needs the permissions.view permission/),
        ).toBeInTheDocument();
        expect(observed.some((request) => request.url.includes('/admin/permissions'))).toBe(false);
    });
});
