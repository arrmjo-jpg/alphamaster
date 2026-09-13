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

    it('is what the real registry says: one Settings section, and no loose access items', () => {
        const everything = MODULES.map((module) => module.permission).filter(
            (permission): permission is string => permission !== undefined,
        );

        const tree = navigationTree(everything, MODULES, MODULE_GROUPS);
        const group = tree.find((entry) => entry.kind === 'group');

        expect(group?.kind === 'group' && group.group.id).toBe('settings');
        expect(group?.kind === 'group' && group.children.map((child) => child.id)).toEqual([
            'settings',
            'users',
            'roles',
            'permissions',
        ]);

        // The four of them are children now, and nowhere else.
        expect(
            tree.filter((entry) => entry.kind === 'module').map((entry) => entry.module.id),
        ).toEqual([
            'dashboard',
            'integrations',
            // Beside the vendor configuration it depends on, not beside the workshop
            // that is its first consumer.
            'ai',
            'languages',
            'translations',
            'media',
            'notifications',
            'operations',
            'account',
        ]);
    });

    it('keeps the address each grouped module already had, so old links resolve', () => {
        const paths = Object.fromEntries(MODULES.map((module) => [module.id, module.path]));

        expect(paths['users']).toBe('/access/users');
        expect(paths['roles']).toBe('/access/roles');
        expect(paths['settings']).toBe('/settings');
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
