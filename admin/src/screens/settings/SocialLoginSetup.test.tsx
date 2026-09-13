import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { render, screen } from '@testing-library/react';
import { HttpResponse, http } from 'msw';
import { describe, expect, it } from 'vitest';

import { server } from '@/test/server';

import type { SocialLoginSetupState } from './api';
import { SocialLoginSetup } from './SocialLoginSetup';

import '@/i18n';

/**
 * The social sign-in setup summary.
 *
 * What matters is that it shows the operator exactly what the platform reports — the
 * addresses to register, the ones it will ignore and why, what is still missing — and
 * that with nothing configured it shows no address at all rather than an example.
 */

function state(overrides: Partial<SocialLoginSetupState> = {}): SocialLoginSetupState {
    return {
        enabled: false,
        registration_enabled: true,
        production: true,
        ready: false,
        redirect_uris: [],
        register_in_provider_console: [],
        password_reset_url: { value: null, usable: false, problem: 'missing', problem_label: null },
        providers: [
            {
                key: 'google',
                label: 'Google',
                active: false,
                effective: false,
                client_id_configured: false,
                client_secret_configured: false,
                missing: ['client_id', 'client_secret'],
            },
        ],
        issues: [
            {
                code: 'no_redirect_uris',
                label: 'No return address is configured, so no social sign-in can start.',
            },
        ],
        ...overrides,
    };
}

function show(setup: SocialLoginSetupState) {
    server.use(
        http.get('*/api/v1/admin/auth/social-login/setup', () =>
            HttpResponse.json({ success: true, data: setup }),
        ),
    );

    const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });

    return render(
        <QueryClientProvider client={client}>
            <SocialLoginSetup />
        </QueryClientProvider>,
    );
}

describe('social sign-in setup', () => {
    it('says what is missing and offers no address of its own', async () => {
        const { container } = show(state());

        expect(await screen.findByText('Not ready yet')).toBeInTheDocument();
        expect(
            screen.getByText('No return address is configured, so no social sign-in can start.'),
        ).toBeInTheDocument();
        expect(
            screen.getByText(
                'None yet. Add the return addresses of your client application below.',
            ),
        ).toBeInTheDocument();
        expect(screen.getByText(/Missing: client_id, client_secret/)).toBeInTheDocument();
        expect(screen.getByText(/Client secret: not configured/)).toBeInTheDocument();
        expect(screen.getByText(/Social sign-in is switched off/)).toBeInTheDocument();
        expect(container.textContent).not.toContain('://');
        expect(container.textContent).not.toContain('localhost');
    });

    it('lists exactly the addresses to register with Google, and marks one it ignores', async () => {
        show(
            state({
                redirect_uris: [
                    {
                        uri: 'https://client.example.test/auth/callback',
                        usable: true,
                        problem: null,
                        problem_label: null,
                    },
                    {
                        uri: 'http://localhost/auth/callback',
                        usable: false,
                        problem: 'loopback',
                        problem_label:
                            'in production it must not point at localhost or a loopback address.',
                    },
                ],
                register_in_provider_console: ['https://client.example.test/auth/callback'],
            }),
        );

        expect(
            await screen.findByText('Authorized redirect URIs to register in Google Cloud Console'),
        ).toBeInTheDocument();
        expect(screen.getByText('https://client.example.test/auth/callback')).toBeInTheDocument();
        expect(screen.getByText('http://localhost/auth/callback')).toBeInTheDocument();
        expect(
            screen.getByText(
                'Ignored in this environment: in production it must not point at localhost or a loopback address.',
            ),
        ).toBeInTheDocument();
    });

    it('shows the reset page and says when everything is ready', async () => {
        show(
            state({
                ready: true,
                issues: [],
                register_in_provider_console: ['https://client.example.test/auth/callback'],
                password_reset_url: {
                    value: 'https://client.example.test/reset-password',
                    usable: true,
                    problem: null,
                    problem_label: null,
                },
                providers: [
                    {
                        key: 'google',
                        label: 'Google',
                        active: true,
                        effective: true,
                        client_id_configured: true,
                        client_secret_configured: true,
                        missing: [],
                    },
                ],
            }),
        );

        expect(
            await screen.findByText(
                'Ready: a usable return address, a usable password reset page and a configured provider.',
            ),
        ).toBeInTheDocument();
        expect(screen.getByText('https://client.example.test/reset-password')).toBeInTheDocument();
        expect(screen.getByText('Configured and switched on')).toBeInTheDocument();
        expect(screen.queryByText(/Missing:/)).not.toBeInTheDocument();
    });

    it('notes when http and localhost are accepted because this is not production', async () => {
        show(state({ production: false }));

        expect(await screen.findByText(/This is not a production environment/)).toBeInTheDocument();
    });
});
