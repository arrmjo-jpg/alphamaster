import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import type { RequestHandler } from 'msw';
import { HttpResponse, http } from 'msw';
import { MemoryRouter } from 'react-router';
import { describe, expect, it } from 'vitest';

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

function renderScreen(me: Record<string, unknown>, extra: RequestHandler[] = []) {
    let served = 0;

    server.use(
        LANGUAGES,
        HEALTH,
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
        ...extra,
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
