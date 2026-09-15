import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import type { RequestHandler } from 'msw';
import { HttpResponse, http } from 'msw';
import { MemoryRouter } from 'react-router';
import { describe, expect, it, vi } from 'vitest';

import { AppProviders } from '@/app/AppProviders';
import { AuthGate } from '@/auth/AuthGate';
import { AccountScreen } from '@/screens/AccountScreen';
import { observed, server } from '@/test/server';

import '@/i18n';

/**
 * The account's own page, and the one flow on it.
 *
 * Confirming a number is the only thing here that writes, and what it must never do
 * is name an account: the endpoints take no identifier, and a request carrying one
 * would mean the console had invented a way to confirm somebody else's number. That
 * is asserted rather than assumed.
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

/** What `GET /profile` serves for the viewer, before a test changes it. */
function profileFixture(overrides: Record<string, unknown> = {}) {
    return {
        id: '01hzzviewer',
        account_type: 'admin',
        name: 'Nadia Haddad',
        email: 'nadia@example.test',
        email_verified: true,
        email_verified_at: '2026-01-01T00:00:00+00:00',
        phone: null,
        phone_verified: false,
        phone_verified_at: null,
        has_password: true,
        preferred_locale: null,
        bio: null,
        avatar_url: null,
        location: {
            country_code: null,
            region: null,
            city: null,
            latitude: null,
            longitude: null,
            updated_at: null,
        },
        links: [],
        ...overrides,
    };
}

const MFA_ON = {
    enabled: true,
    satisfies_policy: true,
    methods: ['totp'],
    methods_options: [{ value: 'totp', label: 'Authenticator app' }],
    available_methods: ['totp'],
    available_methods_options: [{ value: 'totp', label: 'Authenticator app' }],
    recovery_codes_remaining: 8,
};

function renderScreen(me: Record<string, unknown>, extra: RequestHandler[] = []) {
    let served = 0;

    server.use(
        // First, so a test's own handler answers before the defaults below.
        ...extra,
        LANGUAGES,
        HEALTH,
        http.get('*/api/v1/profile', () =>
            HttpResponse.json({ success: true, data: profileFixture() }),
        ),
        http.get('*/api/v1/auth/mfa', () => HttpResponse.json({ success: true, data: MFA_ON })),
        http.get('*/api/v1/auth/me', () => {
            served += 1;

            return HttpResponse.json({
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
                    permissions: [],
                    ...me,
                },
            });
        }),
    );

    render(
        <MemoryRouter>
            <AppProviders>
                <AuthGate>
                    <AccountScreen />
                </AuthGate>
            </AppProviders>
        </MemoryRouter>,
    );

    return () => served;
}

describe('the account page', () => {
    it('offers nothing to confirm when the platform has no number', async () => {
        renderScreen({});

        expect(
            await screen.findByText('The platform has no phone number for this account.'),
        ).toBeInTheDocument();

        expect(screen.queryByRole('button', { name: 'Send a code' })).not.toBeInTheDocument();
    });

    it('reports a confirmed number and offers no way to confirm it again', async () => {
        renderScreen({
            phone: '+962790000111',
            phone_verified: true,
            phone_verified_at: '2026-02-02T09:00:00+00:00',
        });

        expect(await screen.findByText('+962790000111')).toBeInTheDocument();
        expect(screen.getByText('Number confirmed')).toBeInTheDocument();
        expect(screen.queryByRole('button', { name: 'Send a code' })).not.toBeInTheDocument();
    });

    it('sends a code and confirms the number, naming nobody', async () => {
        // The session the API would serve, and the write that changes it. Held here so
        // the confirmation moves the same state `/auth/me` reads — otherwise the badge
        // could only ever flip because the client decided it should.
        const session: Record<string, unknown> = {
            phone: '+962790000111',
            phone_verified: false,
            phone_verified_at: null,
        };

        const servedCount = renderScreen(session, [
            http.post('*/api/v1/auth/phone/verify/send', () =>
                HttpResponse.json({
                    success: true,
                    message: 'sent',
                    data: { destination: '*********0111' },
                }),
            ),
            http.post('*/api/v1/auth/phone/verify', () => {
                session['phone_verified'] = true;
                session['phone_verified_at'] = '2026-09-10T10:00:00+00:00';

                return HttpResponse.json({
                    success: true,
                    message: 'verified',
                    data: {
                        phone_verified: true,
                        phone_verified_at: '2026-09-10T10:00:00+00:00',
                    },
                });
            }),
        ]);

        const before = servedCount();

        await userEvent.click(await screen.findByRole('button', { name: 'Send a code' }));

        // Masked, and the screen repeats exactly what the server said rather than
        // rebuilding it from the number it happens to be holding.
        expect(await screen.findByText(/\*+0111/)).toBeInTheDocument();

        await userEvent.type(screen.getByLabelText(/^Verification code/), '123456');
        await userEvent.click(screen.getByRole('button', { name: 'Confirm the number' }));

        // The session is re-asked rather than patched: the badge is whatever the API
        // says, not what the client hoped the write did.
        await screen.findByText('Number confirmed');
        expect(servedCount()).toBeGreaterThan(before);

        const sent = observed.filter((request) => request.url.includes('/phone/verify'));

        expect(sent).not.toHaveLength(0);

        for (const request of sent) {
            // No account in the path, and none in the query. There is no shape of this
            // request that could be aimed at somebody else.
            expect(new URL(request.url).search).toBe('');
            expect(request.url).toMatch(/\/auth\/phone\/verify(\/send)?$/);
        }
    });

    it('runs the countdown the server asked for rather than one of its own', async () => {
        renderScreen({ phone: '+962790000111' }, [
            http.post('*/api/v1/auth/phone/verify/send', () =>
                HttpResponse.json(
                    {
                        success: false,
                        error: {
                            code: 'PHONE_VERIFICATION_THROTTLED',
                            message: 'Wait before asking for another code.',
                            details: { retry_after: 45 },
                        },
                    },
                    { status: 429 },
                ),
            ),
        ]);

        await userEvent.click(await screen.findByRole('button', { name: 'Send a code' }));

        expect(
            await screen.findByRole('button', { name: 'Send again in 45 seconds' }),
        ).toBeDisabled();
    });
});

describe('managing the account from the console (ADR 0057)', () => {
    it('saves only what changed, through the account\'s own endpoint', async () => {
        let sent: unknown = null;

        renderScreen({}, [
            http.patch('*/api/v1/profile', async ({ request }) => {
                sent = await request.json();

                return HttpResponse.json({
                    success: true,
                    message: 'updated',
                    data: profileFixture({ name: 'Nadia H.' }),
                });
            }),
        ]);

        const name = await screen.findByLabelText(/^Name/);

        await userEvent.clear(name);
        await userEvent.type(name, 'Nadia H.');
        await userEvent.click(screen.getByRole('button', { name: 'Save profile' }));

        expect(await screen.findByText('Saved.')).toBeInTheDocument();
        expect(sent).toEqual({ name: 'Nadia H.' });

        for (const request of observed.filter((seen) => seen.method === 'PATCH')) {
            // No account in the path: there is nobody else's profile to reach.
            expect(new URL(request.url).pathname).toBe('/api/v1/profile');
        }
    });

    it('shows an administrator their address, and who changes it', async () => {
        renderScreen({});

        expect(await screen.findByText(/changed through account management/)).toBeInTheDocument();
        expect(screen.queryByDisplayValue('nadia@example.test')).not.toBeInTheDocument();
    });

    it('changes the password with the current one, and catches a mismatch before sending', async () => {
        let sent: unknown = null;

        renderScreen({}, [
            http.put('*/api/v1/profile/password', async ({ request }) => {
                sent = await request.json();

                return HttpResponse.json({ success: true, message: 'changed', data: null });
            }),
        ]);

        await userEvent.type(await screen.findByLabelText(/^Current password/), 'old-secret');
        await userEvent.type(screen.getByLabelText(/^New password/), 'A-new-passphrase');
        await userEvent.type(screen.getByLabelText(/^Repeat the new password/), 'A-different-one');

        expect(screen.getByText('The two new passwords are not the same.')).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Change password' })).toBeDisabled();

        const repeat = screen.getByLabelText(/^Repeat the new password/);

        await userEvent.clear(repeat);
        await userEvent.type(repeat, 'A-new-passphrase');
        await userEvent.click(screen.getByRole('button', { name: 'Change password' }));

        expect(
            await screen.findByText('Your password was changed. Your other sessions were signed out.'),
        ).toBeInTheDocument();
        expect(sent).toEqual({
            current_password: 'old-secret',
            password: 'A-new-passphrase',
            password_confirmation: 'A-new-passphrase',
        });
    });

    it('reports the second factor, and turning it off re-asks the session', async () => {
        let sent: unknown = null;

        const served = renderScreen({}, [
            http.delete('*/api/v1/auth/mfa', async ({ request }) => {
                sent = await request.json();

                return HttpResponse.json({
                    success: true,
                    message: 'disabled',
                    data: { enabled: false, tokens_revoked: true },
                });
            }),
        ]);

        expect(await screen.findByText('On')).toBeInTheDocument();
        expect(screen.getByText('Recovery codes left: 8')).toBeInTheDocument();
        expect(screen.getByText('Methods: Authenticator app')).toBeInTheDocument();

        const before = served();

        await userEvent.type(screen.getByLabelText(/^Code/), '12345678');
        await userEvent.click(
            screen.getByRole('button', { name: 'Turn off two-factor authentication' }),
        );

        // An administrator's sessions are all gone; the session is re-asked, which lands on sign-in.
        await vi.waitFor(() => expect(served()).toBeGreaterThan(before));
        expect(sent).toEqual({ code: '12345678' });
    });

    it('uploads a picture through the account\'s own endpoint and shows it from the session', async () => {
        let picture: string | null = null;

        renderScreen({}, [
            http.post('*/api/v1/profile/avatar', () => {
                picture = '/storage/nadia.png';

                return HttpResponse.json(
                    {
                        success: true,
                        message: 'accepted',
                        data: { media_id: '01hzzavatar', status: 'ready', avatar_url: picture },
                    },
                    { status: 201 },
                );
            }),
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
                        avatar_url: picture,
                        abilities: ['admin:access'],
                        roles: [],
                        permissions: [],
                    },
                }),
            ),
        ]);

        await userEvent.upload(
            await screen.findByLabelText('Upload a picture'),
            new File([new Uint8Array([137, 80, 78, 71])], 'nadia.png', { type: 'image/png' }),
        );

        expect(await screen.findByRole('img', { name: 'Picture' })).toHaveAttribute(
            'src',
            '/storage/nadia.png',
        );
        expect(screen.getByRole('button', { name: 'Remove picture' })).toBeInTheDocument();
    });
});
