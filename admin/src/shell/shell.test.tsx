import { render, screen } from '@testing-library/react';
import { LayoutDashboard, Settings } from 'lucide-react';
import { HttpResponse, http } from 'msw';
import { MemoryRouter } from 'react-router';
import { describe, expect, it, vi } from 'vitest';

import { App } from '@/app/App';
import { AppProviders } from '@/app/AppProviders';
import { AuthGate } from '@/auth/AuthGate';
import type { ModuleManifest } from '@/modules/registry';
import { visibleModules } from '@/modules/registry';
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
