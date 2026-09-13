import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import type { RequestHandler } from 'msw';
import { HttpResponse, http } from 'msw';
import { MemoryRouter } from 'react-router';
import { describe, expect, it } from 'vitest';

import { AppProviders } from '@/app/AppProviders';
import { AuthGate } from '@/auth/AuthGate';
import { DeviceRegistry } from '@/screens/notifications/DeviceRegistry';
import { server } from '@/test/server';

import '@/i18n';

/**
 * The device registry, as an operator reads it.
 *
 * Asserted mostly for what it will not do: show a registration token, or offer a way to
 * register a device on somebody's behalf. A registration is a claim that a handset
 * belongs to an account, and only that account's own client can make it (ADR 0045).
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

function registry(overrides: Record<string, unknown> = {}) {
    return {
        configured: true,
        provider: { driver: 'fcm', label: 'Firebase Cloud Messaging', has_credentials: true },
        available_drivers: ['fcm'],
        last_attempt: null,
        recent_failures: 0,
        devices: [
            {
                id: '01hzzdevice',
                user_id: '01hzzowner',
                platform: 'ios',
                platform_label: 'iPhone or iPad',
                label: 'Sami’s phone',
                token_hint: 'abc123',
                last_seen_at: '2026-09-10T09:00:00+00:00',
                registered_at: '2026-09-01T09:00:00+00:00',
                stale: false,
            },
            {
                id: '01hzzstale',
                user_id: '01hzzowner',
                platform: 'android',
                platform_label: 'Android',
                label: null,
                token_hint: 'zzz999',
                last_seen_at: null,
                registered_at: '2026-06-01T09:00:00+00:00',
                stale: true,
            },
        ],
        total: 2,
        stale: 1,
        ...overrides,
    };
}

function renderRegistry(
    data: Record<string, unknown>,
    mayUpdate = true,
    extra: RequestHandler[] = [],
) {
    server.use(
        LANGUAGES,
        HEALTH,
        http.get('*/api/v1/auth/me', () =>
            HttpResponse.json({
                success: true,
                data: {
                    id: '01hzzviewer',
                    name: 'Nadia Haddad',
                    email: 'nadia@example.test',
                    account_type: 'admin',
                    is_active: true,
                    email_verified: true,
                    email_verified_at: '2026-01-01T00:00:00+00:00',
                    phone: null,
                    phone_verified: false,
                    phone_verified_at: null,
                    abilities: ['admin:access'],
                    roles: [],
                    permissions: ['notifications.view', 'notifications.update'],
                },
            }),
        ),
        http.get('*/api/v1/admin/notifications/devices', () =>
            HttpResponse.json({ success: true, data }),
        ),
        ...extra,
    );

    render(
        <MemoryRouter>
            <AppProviders>
                <AuthGate>
                    <DeviceRegistry mayUpdate={mayUpdate} />
                </AuthGate>
            </AppProviders>
        </MemoryRouter>,
    );
}

describe('the device registry', () => {
    it('reads the push capability beside the devices it would reach', async () => {
        renderRegistry(registry());

        expect(await screen.findByText('Push ready')).toBeInTheDocument();
        expect(screen.getByText('Firebase Cloud Messaging')).toBeInTheDocument();
        expect(screen.getByText('2 registered; 1 not reached in thirty days.')).toBeInTheDocument();
        expect(screen.getByText('Not reached recently')).toBeInTheDocument();
    });

    it('says plainly when no push provider is switched on', async () => {
        renderRegistry(
            registry({ configured: false, provider: null, devices: [], total: 0, stale: 0 }),
        );

        expect(await screen.findByText('Push not ready')).toBeInTheDocument();
        expect(screen.getByText('No push provider is switched on.')).toBeInTheDocument();
        expect(screen.getByText('No device has registered yet.')).toBeInTheDocument();
    });

    it('shows a hint of the token and never the token', async () => {
        renderRegistry(registry());

        expect(await screen.findByText(/…abc123/)).toBeInTheDocument();
        // The API sends none, and the screen has no field that could show one.
        expect(document.body.textContent).not.toMatch(/token:/i);
    });

    it('reports a failing provider as its own warning', async () => {
        renderRegistry(
            registry({
                last_attempt: {
                    status: 'failure',
                    at: '2026-09-10T10:00:00+00:00',
                    error_code: 'AUTH_FAILED',
                    error_message:
                        'The service account could not be exchanged for an access token.',
                    duration_ms: 120,
                    units: null,
                },
                recent_failures: 3,
            }),
        );

        expect(await screen.findByText('Last push failed')).toBeInTheDocument();
        expect(
            screen.getByText('The service account could not be exchanged for an access token.'),
        ).toBeInTheDocument();
        expect(screen.getByText('3 failed pushes in the last day.')).toBeInTheDocument();
    });

    it('removes a device when the operator may change notifications', async () => {
        const removed: string[] = [];

        renderRegistry(registry(), true, [
            http.delete('*/api/v1/admin/notifications/devices/:id', ({ params }) => {
                removed.push(String(params['id']));

                return HttpResponse.json({
                    success: true,
                    message: 'ok',
                    data: { id: params['id'] },
                });
            }),
        ]);

        await userEvent.click(await screen.findByRole('button', { name: 'Remove Sami’s phone' }));

        expect(removed).toEqual(['01hzzdevice']);
    });

    it('offers no removal without the permission, and never a registration', async () => {
        renderRegistry(registry(), false);

        await screen.findByText('Sami’s phone');

        expect(screen.queryByRole('button', { name: /Remove/ })).not.toBeInTheDocument();
        expect(screen.queryByRole('button', { name: /Register|Add/ })).not.toBeInTheDocument();
    });
});
