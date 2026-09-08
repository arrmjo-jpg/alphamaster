import { render, screen } from '@testing-library/react';
import { HttpResponse, http } from 'msw';
import { MemoryRouter } from 'react-router';
import { describe, expect, it } from 'vitest';

import { AppProviders } from '@/app/AppProviders';
import { AuthGate } from '@/auth/AuthGate';
import { DashboardScreen } from '@/screens/DashboardScreen';
import { observed, server } from '@/test/server';

import { summarise } from './capabilities';
import type { IntegrationProvider, IntegrationUsage } from './api';

import '@/i18n';

/**
 * The judgement is tested without rendering, and the rendering is tested without
 * re-deciding — because the interesting part of this screen is what it concludes
 * from two sources that can disagree.
 */

function provider(overrides: Partial<IntegrationProvider>): IntegrationProvider {
    return {
        id: 'p1',
        capability: 'sms',
        capability_label: 'SMS',
        driver: 'twilio',
        label: 'Twilio',
        settings: null,
        has_credentials: true,
        is_active: true,
        is_default: true,
        priority: 1,
        updated_at: null,
        ...overrides,
    };
}

function usage(overrides: Partial<IntegrationUsage>): IntegrationUsage {
    return {
        id: 'u1',
        capability: 'sms',
        driver: 'twilio',
        status: 'success',
        reference: null,
        error_code: null,
        error_message: null,
        duration_ms: 120,
        created_at: '2026-09-08T10:00:00+00:00',
        ...overrides,
    };
}

describe('what a capability is actually doing', () => {
    it('calls a configured provider with no attempts unused, not working', () => {
        const [row] = summarise([provider({})], [], (c) => c);

        expect(row?.state).toBe('idle');
        expect(row?.tone).toBe('info');
    });

    it('does not call a capability with no active provider a failure', () => {
        // A platform that does not send SMS has no SMS provider, and that is correct
        // rather than broken.
        const [row] = summarise([provider({ is_active: false })], [], (c) => c);

        expect(row?.state).toBe('unconfigured');
        expect(row?.tone).toBe('neutral');
    });

    it('reports a configured provider whose every attempt failed as failing', () => {
        // The case this panel exists for: perfectly configured, and not working.
        const [row] = summarise(
            [provider({})],
            [usage({ status: 'failure', error_code: 'AUTH_FAILED' })],
            (c) => c,
        );

        expect(row?.state).toBe('failing');
        expect(row?.tone).toBe('danger');
        expect(row?.lastFailure?.error_code).toBe('AUTH_FAILED');
    });

    it('separates some failures from all of them', () => {
        const [row] = summarise(
            [provider({})],
            [usage({ id: 'u1', status: 'failure' }), usage({ id: 'u2', status: 'success' })],
            (c) => c,
        );

        expect(row?.state).toBe('degraded');
        expect(row?.tone).toBe('warning');
        expect(row?.failures).toBe(1);
        expect(row?.attempts).toBe(2);
    });

    it('reports a working capability as working', () => {
        const [row] = summarise([provider({})], [usage({})], (c) => c);

        expect(row?.state).toBe('healthy');
        expect(row?.tone).toBe('success');
    });

    it('keeps capabilities apart rather than mixing their usage', () => {
        const rows = summarise(
            [
                provider({ id: 'p1', capability: 'sms' }),
                provider({ id: 'p2', capability: 'captcha', capability_label: 'Captcha' }),
            ],
            [usage({ capability: 'sms', status: 'failure' })],
            (c) => c,
        );

        expect(rows.map((row) => [row.capability, row.state])).toEqual([
            ['captcha', 'idle'],
            ['sms', 'failing'],
        ]);
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

function admin(permissions: string[]) {
    return {
        id: '01hzz',
        name: 'Nadia Haddad',
        email: 'nadia@example.test',
        account_type: 'admin',
        is_active: true,
        email_verified: true,
        email_verified_at: '2026-01-01T00:00:00+00:00',
        abilities: ['admin:access'],
        roles: ['administrator'],
        permissions,
    };
}

function renderDashboard(permissions: string[]) {
    server.use(
        LANGUAGES,
        HEALTH,
        http.get('*/api/v1/auth/me', () =>
            HttpResponse.json({ success: true, data: admin(permissions) }),
        ),
    );

    return render(
        <MemoryRouter>
            <AppProviders>
                <AuthGate>
                    <DashboardScreen />
                </AuthGate>
            </AppProviders>
        </MemoryRouter>,
    );
}

describe('which panels an operator is shown', () => {
    it('shows the platform panel to every administrator', async () => {
        renderDashboard([]);

        expect(await screen.findByText('Platform')).toBeInTheDocument();
    });

    it('omits a panel the account may not read, and does not request it', async () => {
        // Not merely hidden: the request is never made. Sending one that is certain
        // to be refused would render an error and tell the operator something is
        // wrong when nothing is.
        renderDashboard([]);

        await screen.findByText('Platform');

        expect(screen.queryByText('Integrations')).not.toBeInTheDocument();
        expect(screen.queryByText('Recent administrative activity')).not.toBeInTheDocument();

        const admin = observed.filter((request) => request.url.includes('/api/v1/admin/'));
        expect(admin.map((request) => request.url)).toEqual([]);
    });

    it('shows a panel the account may read', async () => {
        server.use(
            http.get('*/api/v1/admin/audit', () =>
                HttpResponse.json({
                    success: true,
                    data: [
                        {
                            id: 'a1',
                            actor_id: null,
                            action: 'settings.updated',
                            action_label: 'Settings updated',
                            subject: 'mail',
                            outcome: 'failed',
                            context: null,
                            correlation_id: null,
                            created_at: '2026-09-08T09:59:00+00:00',
                        },
                    ],
                    meta: { pagination: { total: 41 } },
                }),
            ),
        );

        renderDashboard(['audit.view']);

        expect(await screen.findByText('Settings updated')).toBeInTheDocument();
        expect(screen.getByText('Failed')).toBeInTheDocument();
        expect(screen.getByText('41 entries')).toBeInTheDocument();
    });

    it('keeps a failing panel from taking the screen with it', async () => {
        server.use(
            http.get('*/api/v1/admin/audit', () =>
                HttpResponse.json(
                    { success: false, error: { code: 'FORBIDDEN', message: 'Not permitted.' } },
                    { status: 403 },
                ),
            ),
        );

        renderDashboard(['audit.view']);

        expect(await screen.findByText('Not permitted.')).toBeInTheDocument();
        // The rest of the dashboard is still there.
        expect(screen.getByText('Platform')).toBeInTheDocument();
    });
});
