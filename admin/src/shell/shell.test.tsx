import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { LayoutDashboard, Settings } from 'lucide-react';
import { HttpResponse, http } from 'msw';
import { MemoryRouter } from 'react-router';
import { describe, expect, it, vi } from 'vitest';

import { App } from '@/app/App';
import { AppProviders } from '@/app/AppProviders';
import { AuthGate } from '@/auth/AuthGate';
import type { ModuleGroup, ModuleManifest } from '@/modules/registry';
import { MODULE_GROUPS, MODULES, navigationTree, visibleModules } from '@/modules/registry';
import { ErrorBoundary } from '@/shell/ErrorBoundary';
import { Navigation } from '@/shell/Navigation';
import { server } from '@/test/server';

import '@/i18n';

/**
 * The shell knows nothing about any module, and this is what says so.
 *
 * Every assertion about filtering runs against fixtures rather than the real
 * registry, so these keep meaning the same thing as modules are added. The routing
 * tests use the real one, because what they check is that the registry and the
 * router agree.
 */

const FIXTURES: ModuleManifest[] = [
    {
        id: 'open',
        path: '/open',
        label: 'modules.dashboard',
        icon: LayoutDashboard,
        order: 20,
        component: () => null,
    },
    {
        id: 'restricted',
        path: '/restricted',
        label: 'shell.content',
        icon: Settings,
        permission: 'settings.view',
        order: 10,
        component: () => null,
    },
];

const GROUPS: ModuleGroup[] = [{ id: 'box', label: 'modules.settings', icon: Settings, order: 15 }];

const GROUPED: ModuleManifest[] = [
    ...FIXTURES,
    {
        id: 'inside',
        path: '/inside',
        label: 'modules.users',
        icon: Settings,
        group: 'box',
        order: 5,
        component: () => null,
    },
    {
        id: 'inside-restricted',
        path: '/inside-restricted',
        label: 'modules.roles',
        icon: Settings,
        group: 'box',
        permission: 'roles.view',
        order: 6,
        component: () => null,
    },
    {
        id: 'orphaned',
        path: '/orphaned',
        label: 'modules.media',
        icon: Settings,
        // Naming a group no manifest declares. It stays a top-level item rather than
        // disappearing into a heading nothing renders.
        group: 'no-such-group',
        order: 30,
        component: () => null,
    },
];

describe('which modules a viewer may see', () => {
    it('always includes a module that names no permission', () => {
        expect(visibleModules([], FIXTURES).map((module) => module.id)).toEqual(['open']);
    });

    it('includes a permitted module and orders by the manifest, not by declaration', () => {
        expect(visibleModules(['settings.view'], FIXTURES).map((module) => module.id)).toEqual([
            'restricted',
            'open',
        ]);
    });

    it('is not fooled by holding some other permission', () => {
        expect(visibleModules(['users.view'], FIXTURES).map((module) => module.id)).toEqual([
            'open',
        ]);
    });
});

describe('the navigation', () => {
    it('renders a link for each visible module and nothing for the rest', () => {
        render(
            <MemoryRouter>
                <Navigation modules={FIXTURES} permissions={[]} />
            </MemoryRouter>,
        );

        expect(screen.getByRole('link', { name: 'Dashboard' })).toBeInTheDocument();
        expect(screen.queryByRole('link', { name: 'Main content' })).not.toBeInTheDocument();
    });

    it('resolves labels through i18n rather than printing the key', () => {
        render(
            <MemoryRouter>
                <Navigation modules={FIXTURES} permissions={['settings.view']} />
            </MemoryRouter>,
        );

        expect(screen.queryByText('modules.dashboard')).not.toBeInTheDocument();
        expect(screen.getByText('Dashboard')).toBeInTheDocument();
    });
});

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

const ADMIN = {
    id: '01hzz',
    name: 'Nadia Haddad',
    email: 'nadia@example.test',
    account_type: 'admin',
    is_active: true,
    email_verified: true,
    email_verified_at: '2026-01-01T00:00:00+00:00',
    abilities: ['admin:access'],
    roles: ['administrator'],
    permissions: ['settings.view'],
};

function renderAt(pathname: string) {
    server.use(
        LANGUAGES,
        HEALTH,
        http.get('*/api/v1/auth/me', () => HttpResponse.json({ success: true, data: ADMIN })),
    );

    return render(
        <MemoryRouter initialEntries={[pathname]}>
            <AppProviders>
                <AuthGate>
                    <App />
                </AuthGate>
            </AppProviders>
        </MemoryRouter>,
    );
}

describe('routing', () => {
    it('sends the root to the home module rather than showing an empty frame', async () => {
        renderAt('/');

        expect(await screen.findByText('Nadia Haddad')).toBeInTheDocument();
    });

    it('renders the module that claims a path', async () => {
        renderAt('/dashboard');

        expect(await screen.findByText('Nadia Haddad')).toBeInTheDocument();
    });

    it('names the path when no module claims it', async () => {
        renderAt('/nowhere/at/all');

        expect(await screen.findByText('Nothing lives at this address')).toBeInTheDocument();
        // The usual cause is a stale link, and the operator can only tell which one
        // if the address is on the screen.
        expect(screen.getByText('/nowhere/at/all')).toBeInTheDocument();
    });
});

describe('when a component throws', () => {
    it('says so instead of leaving a white screen', () => {
        // React logs the caught error itself; silencing it keeps the run readable
        // without hiding anything the boundary is responsible for.
        const consoleError = vi.spyOn(console, 'error').mockImplementation(() => undefined);

        function Explode(): never {
            throw new Error('a component gave up');
        }

        render(
            <ErrorBoundary>
                <Explode />
            </ErrorBoundary>,
        );

        expect(screen.getByText('The console stopped')).toBeInTheDocument();
        expect(screen.getByText('a component gave up')).toBeInTheDocument();

        consoleError.mockRestore();
    });
});

/** The tree a viewer holding every declared permission would see. */
function fullTree() {
    const everything = MODULES.map((module) => module.permission).filter(
        (permission): permission is string => permission !== undefined,
    );

    return navigationTree(everything, MODULES, MODULE_GROUPS);
}

function sections(tree: ReturnType<typeof navigationTree>): string[] {
    return tree
        .filter((entry) => entry.kind === 'group')
        .map((entry) => (entry.kind === 'group' ? entry.group.id : ''));
}

function childrenOf(tree: ReturnType<typeof navigationTree>, id: string): string[] {
    const found = tree.find((entry) => entry.kind === 'group' && entry.group.id === id);

    return found?.kind === 'group' ? found.children.map((child) => child.id) : [];
}

describe('the navigation tree', () => {
    it('puts a module under the group it named and leaves it out of the top level', () => {
        const tree = navigationTree(['settings.view', 'roles.view'], GROUPED, GROUPS);

        expect(
            tree.map((entry) =>
                entry.kind === 'group' ? `group:${entry.group.id}` : entry.module.id,
            ),
        ).toEqual(['restricted', 'group:box', 'open', 'orphaned']);

        const group = tree.find((entry) => entry.kind === 'group');
        expect(group?.kind === 'group' && group.children.map((child) => child.id)).toEqual([
            'inside',
            'inside-restricted',
        ]);
    });

    it('drops a child the viewer may not see, and the group with its last one', () => {
        const some = navigationTree([], GROUPED, GROUPS).find((entry) => entry.kind === 'group');
        expect(some?.kind === 'group' && some.children.map((child) => child.id)).toEqual([
            'inside',
        ]);

        // A heading with nothing under it tells a restricted operator exactly what
        // they are missing, so it does not render at all.
        const none = navigationTree(
            [],
            GROUPED.filter((module) => module.id !== 'inside'),
            GROUPS,
        );
        expect(none.some((entry) => entry.kind === 'group')).toBe(false);
    });

    it('is what the real registry says: configuration and access are separate sections', () => {
        const tree = fullTree();

        expect(sections(tree)).toEqual(['access', 'settings']);

        expect(childrenOf(tree, 'settings')).toEqual([
            'settings',
            'languages',
            'translations',
            'media',
            'notifications',
            'integrations',
            'ai',
            'operations',
        ]);

        expect(childrenOf(tree, 'access')).toEqual(['users', 'roles', 'permissions']);

        // Dashboard is the one screen that belongs to neither section. Nothing else is
        // loose — Operations included, which now sits last in Settings.
        expect(
            tree.filter((entry) => entry.kind === 'module').map((entry) => entry.module.id),
        ).toEqual(['dashboard']);
    });

    /**
     * The rule the reorganisation exists for.
     *
     * Access management is not configuration of the same kind as a date format: it is
     * the set of people who can perform every operation the rest of the console offers.
     * Filing it under Settings is what these forbid, in the one place the answer is
     * decided rather than in the markup that happens to render it.
     */
    it('does not file users, roles or permissions under Settings', () => {
        const tree = fullTree();

        for (const id of ['users', 'roles', 'permissions']) {
            expect(childrenOf(tree, 'settings')).not.toContain(id);
            expect(childrenOf(tree, 'access')).toContain(id);
        }
    });

    it('keeps Settings holding only what configures the platform', () => {
        // Each of these is a system setting: what the platform serves, stores, sends
        // or talks to. None of them is a person.
        expect(childrenOf(fullTree(), 'settings')).toEqual([
            'settings',
            'languages',
            'translations',
            'media',
            'notifications',
            'integrations',
            'ai',
            'operations',
        ]);
    });

    it('keeps the address each grouped module already had, so old links resolve', () => {
        const paths = Object.fromEntries(MODULES.map((module) => [module.id, module.path]));

        // Regrouping is presentation. Every one of these was reachable at this address
        // before the sections changed and still is, so a bookmark or a shared link
        // survives the reorganisation.
        expect(paths['users']).toBe('/access/users');
        expect(paths['roles']).toBe('/access/roles');
        expect(paths['permissions']).toBe('/access/permissions');
        expect(paths['settings']).toBe('/settings');
        expect(paths['languages']).toBe('/languages');
        expect(paths['media']).toBe('/media');
        expect(paths['notifications']).toBe('/notifications');
        expect(paths['integrations']).toBe('/integrations');
        expect(paths['operations']).toBe('/operations');
        expect(paths['dashboard']).toBe('/dashboard');
        expect(paths['translations']).toBe('/translations');
        expect(paths['ai']).toBe('/ai');
        expect(paths['account']).toBe('/account');
    });

    it('keeps the account page routable, searchable and out of the navigation', () => {
        // Somewhere a person can go, but not somewhere in the system: the router and
        // the "Go to" search both see it, and the sidebar does not.
        const all = MODULES.map((module) => module.permission).filter(
            (permission): permission is string => permission !== undefined,
        );

        expect(visibleModules(all).map((module) => module.id)).toContain('account');

        const listed = fullTree().flatMap((entry) =>
            entry.kind === 'group' ? entry.children.map((child) => child.id) : [entry.module.id],
        );

        expect(listed).not.toContain('account');
    });

    it('hides a section from a viewer who may see nothing in it', () => {
        // An operator who may read settings but not accounts gets the Settings section
        // and no Access heading at all — an empty heading tells them exactly what they
        // are missing.
        const configOnly = navigationTree(['settings.view'], MODULES, MODULE_GROUPS);

        expect(sections(configOnly)).toEqual(['settings']);

        // And the other way round.
        const accessOnly = navigationTree(
            ['users.view', 'roles.view', 'permissions.view'],
            MODULES,
            MODULE_GROUPS,
        );

        expect(sections(accessOnly)).toContain('access');
        expect(childrenOf(accessOnly, 'access')).toEqual(['users', 'roles', 'permissions']);
    });

    it('still filters children one by one inside a section', () => {
        // Holding one access permission opens the section on that child alone. The
        // section surviving is not the same thing as the section being complete.
        const tree = navigationTree(['roles.view'], MODULES, MODULE_GROUPS);

        expect(childrenOf(tree, 'access')).toEqual(['roles']);
    });
});

describe('the navigation, with sections', () => {
    it('renders a section as a disclosure rather than a link', () => {
        render(
            <MemoryRouter>
                <Navigation groups={GROUPS} modules={GROUPED} permissions={[]} />
            </MemoryRouter>,
        );

        // There is nothing at a section to navigate to, so it is not a link.
        expect(screen.queryByRole('link', { name: 'Settings' })).not.toBeInTheDocument();

        const heading = screen.getByRole('button', { name: 'Expand Settings' });
        expect(heading).toHaveAttribute('aria-expanded', 'false');
    });

    it('opens and closes cleanly, and the children come with it', async () => {
        render(
            <MemoryRouter>
                <Navigation groups={GROUPS} modules={GROUPED} permissions={[]} />
            </MemoryRouter>,
        );

        expect(screen.queryByRole('link', { name: 'Users' })).not.toBeInTheDocument();

        await userEvent.click(screen.getByRole('button', { name: 'Expand Settings' }));

        expect(screen.getByRole('link', { name: 'Users' })).toHaveAttribute('href', '/inside');
        expect(screen.getByRole('button', { name: 'Collapse Settings' })).toHaveAttribute(
            'aria-expanded',
            'true',
        );

        await userEvent.click(screen.getByRole('button', { name: 'Collapse Settings' }));

        expect(screen.queryByRole('link', { name: 'Users' })).not.toBeInTheDocument();
    });

    it('opens the section that owns the address, including one typed by hand', () => {
        render(
            <MemoryRouter initialEntries={['/inside']}>
                <Navigation groups={GROUPS} modules={GROUPED} permissions={[]} />
            </MemoryRouter>,
        );

        // Arriving by URL rather than by click still shows where you are: a collapsed
        // section hiding the active item is the defect this guards against.
        expect(screen.getByRole('button', { name: 'Collapse Settings' })).toHaveAttribute(
            'aria-expanded',
            'true',
        );
        expect(screen.getByRole('link', { name: 'Users' })).toHaveAttribute('aria-current', 'page');
    });

    it('does not draw a section the viewer may see nothing in', () => {
        render(
            <MemoryRouter>
                <Navigation
                    groups={GROUPS}
                    modules={GROUPED.filter((module) => module.id !== 'inside')}
                    permissions={[]}
                />
            </MemoryRouter>,
        );

        expect(screen.queryByRole('button', { name: /Settings/ })).not.toBeInTheDocument();
    });
});
