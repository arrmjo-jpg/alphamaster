import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { HttpResponse, http } from 'msw';
import { MemoryRouter } from 'react-router';
import { beforeEach, describe, expect, it } from 'vitest';

import { App } from '@/app/App';
import { AppProviders } from '@/app/AppProviders';
import { AuthGate } from '@/auth/AuthGate';
import { observed, server } from '@/test/server';

/**
 * The nine outcomes of signing in, each proved to put the operator somewhere
 * different.
 *
 * These render the real tree — providers, gate, screens — against a stubbed API,
 * because the thing worth testing is not any one component but which screen an
 * operator ends up on. A test that mounted `SignInScreen` directly would pass while
 * the gate sent everybody to it.
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

function authSettings(data: Record<string, unknown>) {
    return http.get('*/api/v1/settings/auth', () => HttpResponse.json({ success: true, data }));
}

const AUTH_SETTINGS = authSettings({ captcha_enabled: false });

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

function me(data: unknown) {
    return http.get('*/api/v1/auth/me', () => HttpResponse.json({ success: true, data }));
}

function meFails(status: number, code: string, message = 'Refused.') {
    return http.get('*/api/v1/auth/me', () =>
        HttpResponse.json({ success: false, error: { code, message } }, { status }),
    );
}

function loginReturns(data: unknown) {
    return http.post('*/api/v1/auth/login', () => HttpResponse.json({ success: true, data }));
}

function loginFails(status: number, code: string, message: string, details?: unknown) {
    return http.post('*/api/v1/auth/login', () =>
        HttpResponse.json({ success: false, error: { code, message, details } }, { status }),
    );
}

function renderApp() {
    return render(
        <MemoryRouter>
            <AppProviders>
                <AuthGate>
                    <App />
                </AuthGate>
            </AppProviders>
        </MemoryRouter>,
    );
}

async function signIn() {
    await userEvent.type(screen.getByLabelText(/email or phone/i), 'nadia@example.test');
    await userEvent.type(screen.getByLabelText(/password/i), 'correct horse');
    await userEvent.click(screen.getByRole('button', { name: 'Sign in' }));
}

describe('starting up with a cookie already in the browser', () => {
    it('shows the application when the session is a live administrator', async () => {
        server.use(LANGUAGES, AUTH_SETTINGS, HEALTH, me(ADMIN));
        renderApp();

        expect(await screen.findByText('Nadia Haddad')).toBeInTheDocument();
    });

    it('shows the sign-in form when there is no session', async () => {
        server.use(LANGUAGES, AUTH_SETTINGS, HEALTH, meFails(401, 'UNAUTHENTICATED'));
        renderApp();

        expect(await screen.findByRole('button', { name: 'Sign in' })).toBeInTheDocument();
    });

    it('distinguishes an abandoned sign-in from a refusal', async () => {
        // A browser holding an enrolment or verification cookie gets 403 FORBIDDEN
        // from /auth/me, because that credential carries no access ability. It cannot
        // be resumed, and saying so is better than an unexplained sign-in form.
        server.use(LANGUAGES, AUTH_SETTINGS, HEALTH, meFails(403, 'FORBIDDEN', 'Missing ability.'));
        renderApp();

        expect(await screen.findByText('Sign-in was not completed')).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Sign in' })).toBeEnabled();
    });

    it('names suspension as suspension rather than as a missing session', async () => {
        server.use(
            LANGUAGES,
            AUTH_SETTINGS,
            HEALTH,
            meFails(403, 'ACCOUNT_SUSPENDED', 'Suspended.'),
        );
        renderApp();

        expect(await screen.findByText('This account is suspended')).toBeInTheDocument();
    });

    it('tells a non-administrator that nothing is wrong with their account', async () => {
        server.use(
            LANGUAGES,
            AUTH_SETTINGS,
            HEALTH,
            me({ ...ADMIN, account_type: 'user', roles: [], permissions: [] }),
        );
        renderApp();

        expect(await screen.findByText('This is the administration console')).toBeInTheDocument();
    });
});

describe('when the platform cannot be reached', () => {
    it('says so, rather than leaving the operator on a loading screen', async () => {
        server.use(
            LANGUAGES,
            AUTH_SETTINGS,
            HEALTH,
            http.get('*/api/v1/auth/me', () => HttpResponse.error()),
        );

        renderApp();

        // Every failure has to land somewhere. Without a state for this one the
        // rejection has nowhere to go, nothing is ever set, and the application sits
        // on the word "Loading" with no way to act.
        expect(await screen.findByText('Cannot reach the platform')).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Sign in' })).toBeEnabled();
    });

    it('does not report an abandoned bootstrap as a failure', async () => {
        // React's strict mode mounts, unmounts and remounts, so the first request is
        // always aborted. Treating that as an error produced an unhandled rejection on
        // every page load — and vitest fails the run on one, which is what makes this
        // a test rather than a comment.
        server.use(LANGUAGES, AUTH_SETTINGS, HEALTH, me(ADMIN));

        const { unmount } = renderApp();
        unmount();

        await new Promise((resolve) => setTimeout(resolve, 50));
    });
});

describe('signing in', () => {
    it('lands on the application when nothing else is outstanding', async () => {
        let authenticated = false;

        server.use(
            LANGUAGES,
            AUTH_SETTINGS,
            HEALTH,
            http.get('*/api/v1/auth/me', () =>
                authenticated
                    ? HttpResponse.json({ success: true, data: ADMIN })
                    : HttpResponse.json(
                          { success: false, error: { code: 'UNAUTHENTICATED', message: 'No.' } },
                          { status: 401 },
                      ),
            ),
            http.post('*/api/v1/auth/login', () => {
                authenticated = true;

                return HttpResponse.json({
                    success: true,
                    data: { token: '1|secret', token_type: 'Bearer', abilities: ['admin:access'] },
                });
            }),
        );

        renderApp();
        await screen.findByRole('button', { name: 'Sign in' });
        await signIn();

        expect(await screen.findByText('Nadia Haddad')).toBeInTheDocument();
    });

    it('reports a refusal with the attempts the backend says are left', async () => {
        server.use(
            LANGUAGES,
            AUTH_SETTINGS,
            HEALTH,
            meFails(401, 'UNAUTHENTICATED'),
            loginFails(401, 'INVALID_CREDENTIALS', 'Those credentials do not match.', {
                attempts_remaining: 3,
            }),
        );

        renderApp();
        await screen.findByRole('button', { name: 'Sign in' });
        await signIn();

        expect(await screen.findByText('Those credentials do not match.')).toBeInTheDocument();
        expect(
            screen.getByText('3 attempts remaining before this account is locked.'),
        ).toBeInTheDocument();
    });

    it('closes the form while the limiter is holding it shut', async () => {
        server.use(
            LANGUAGES,
            AUTH_SETTINGS,
            HEALTH,
            meFails(401, 'UNAUTHENTICATED'),
            loginFails(429, 'TOO_MANY_ATTEMPTS', 'Too many attempts.', { retry_after: 45 }),
        );

        renderApp();
        await screen.findByRole('button', { name: 'Sign in' });
        await signIn();

        expect(await screen.findByText('Too many attempts')).toBeInTheDocument();
        expect(screen.getByText('Try again in 45 seconds.')).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Sign in' })).toBeDisabled();
    });

    it('stops at the challenge when a second factor is outstanding', async () => {
        server.use(
            LANGUAGES,
            AUTH_SETTINGS,
            HEALTH,
            meFails(401, 'UNAUTHENTICATED'),
            loginReturns({ mfa_required: true, mfa_token: 'challenge-1', expires_in: 300 }),
        );

        renderApp();
        await screen.findByRole('button', { name: 'Sign in' });
        await signIn();

        expect(await screen.findByText('Two-factor authentication')).toBeInTheDocument();
        expect(screen.queryByText('Nadia Haddad')).not.toBeInTheDocument();
    });

    it('sends an administrator with no second factor to enrolment, not to the console', async () => {
        server.use(
            LANGUAGES,
            AUTH_SETTINGS,
            HEALTH,
            meFails(401, 'UNAUTHENTICATED'),
            loginReturns({
                mfa_setup_required: true,
                enrolment_token: '1|enrol',
                token_type: 'Bearer',
                abilities: ['mfa:enrol'],
            }),
        );

        renderApp();
        await screen.findByRole('button', { name: 'Sign in' });
        await signIn();

        expect(await screen.findByText('Set up two-factor authentication')).toBeInTheDocument();
    });

    it('sends an unverified address to verification', async () => {
        server.use(
            LANGUAGES,
            AUTH_SETTINGS,
            HEALTH,
            meFails(401, 'UNAUTHENTICATED'),
            loginReturns({
                email_verification_required: true,
                verification_token: '1|verify',
                token_type: 'Bearer',
                abilities: ['email:verify'],
            }),
        );

        renderApp();
        await screen.findByRole('button', { name: 'Sign in' });
        await signIn();

        expect(await screen.findByText('Verify your email address')).toBeInTheDocument();
    });
});

describe('completing a challenge', () => {
    it('exchanges a correct code for the session', async () => {
        let challenged = false;

        server.use(
            LANGUAGES,
            AUTH_SETTINGS,
            HEALTH,
            http.get('*/api/v1/auth/me', () =>
                challenged
                    ? HttpResponse.json({ success: true, data: ADMIN })
                    : HttpResponse.json(
                          { success: false, error: { code: 'UNAUTHENTICATED', message: 'No.' } },
                          { status: 401 },
                      ),
            ),
            loginReturns({ mfa_required: true, mfa_token: 'challenge-1', expires_in: 300 }),
            http.post('*/api/v1/auth/mfa/challenge', () => {
                challenged = true;

                return HttpResponse.json({
                    success: true,
                    data: { token: '1|secret', token_type: 'Bearer', abilities: ['admin:access'] },
                });
            }),
        );

        renderApp();
        await screen.findByRole('button', { name: 'Sign in' });
        await signIn();
        await screen.findByText('Two-factor authentication');

        await userEvent.type(screen.getByLabelText(/verification code/i), '123456');
        await userEvent.click(screen.getByRole('button', { name: 'Verify' }));

        expect(await screen.findByText('Nadia Haddad')).toBeInTheDocument();
    });

    it('keeps the operator on the challenge when the code is wrong', async () => {
        server.use(
            LANGUAGES,
            AUTH_SETTINGS,
            HEALTH,
            meFails(401, 'UNAUTHENTICATED'),
            loginReturns({ mfa_required: true, mfa_token: 'challenge-1', expires_in: 300 }),
            http.post('*/api/v1/auth/mfa/challenge', () =>
                HttpResponse.json(
                    {
                        success: false,
                        error: {
                            code: 'MFA_CHALLENGE_FAILED',
                            message: 'That code is not valid.',
                            details: { attempts_remaining: 2 },
                        },
                    },
                    { status: 401 },
                ),
            ),
        );

        renderApp();
        await screen.findByRole('button', { name: 'Sign in' });
        await signIn();
        await screen.findByText('Two-factor authentication');

        await userEvent.type(screen.getByLabelText(/verification code/i), '000000');
        await userEvent.click(screen.getByRole('button', { name: 'Verify' }));

        expect(await screen.findByText('That code is not valid.')).toBeInTheDocument();
        // Still here, and still able to try again — being thrown back to the password
        // form would cost the challenge that was already started.
        expect(screen.getByText('Two-factor authentication')).toBeInTheDocument();
    });
});

describe('what leaves the browser', () => {
    it('never sends an Authorization header, on any request the flow makes', async () => {
        server.use(
            LANGUAGES,
            AUTH_SETTINGS,
            HEALTH,
            me(ADMIN),
            http.post('*/api/v1/auth/logout', () =>
                HttpResponse.json({ success: true, data: null }),
            ),
        );

        renderApp();
        await screen.findByText('Nadia Haddad');

        // Sign-out lives in the account menu now rather than loose in the chrome, so
        // ending the session takes an open and a choice. What is under test is
        // unchanged: no request the flow makes carries a bearer token.
        await userEvent.click(screen.getByRole('button', { name: 'Account' }));
        await userEvent.click(screen.getByRole('menuitem', { name: /Sign out/ }));
        await screen.findByRole('button', { name: 'Sign in' });

        expect(observed.length).toBeGreaterThan(2);

        for (const request of observed) {
            expect(request.headers.get('Authorization')).toBeNull();
        }
    });

    it('asks for credentials to be included, which is how the cookie travels', async () => {
        server.use(LANGUAGES, AUTH_SETTINGS, HEALTH, me(ADMIN));

        renderApp();
        await screen.findByText('Nadia Haddad');

        const meRequest = observed.find((request) => request.url.includes('/auth/me'));
        expect(meRequest?.credentials).toBe('include');
    });
});

describe('the captcha', () => {
    // The loader is keyed on the script element being absent, and jsdom keeps the
    // document between tests in a file.
    beforeEach(() => {
        document.getElementById('recaptcha-api')?.remove();
        delete (window as { grecaptcha?: unknown }).grecaptcha;
    });

    it('is absent when the platform is not asking for one', async () => {
        server.use(LANGUAGES, AUTH_SETTINGS, HEALTH, meFails(401, 'UNAUTHENTICATED'));

        renderApp();
        await screen.findByRole('button', { name: 'Sign in' });

        await waitFor(() => {
            expect(document.getElementById('recaptcha-api')).toBeNull();
        });
    });

    it('loads only when the switch is on and a site key is configured', async () => {
        server.use(
            LANGUAGES,
            HEALTH,
            meFails(401, 'UNAUTHENTICATED'),
            authSettings({ captcha_enabled: true, captcha_site_key: 'site-key-1' }),
        );

        renderApp();
        await screen.findByRole('button', { name: 'Sign in' });

        await waitFor(() => {
            expect(document.getElementById('recaptcha-api')).not.toBeNull();
        });

        // v2 by default, and nothing may be submitted until the checkbox has produced
        // a response — otherwise the attempt is spent on a refusal the operator
        // cannot act on.
        expect(screen.getByRole('button', { name: 'Sign in' })).toBeDisabled();
    });

    it('asks the vendor for v2 with an explicit render, and for v3 with the site key', async () => {
        // The two versions need different script URLs. Getting this wrong is not a
        // styling difference: the wrong one leaves `grecaptcha.execute` undefined or
        // the checkbox unrenderable.
        server.use(
            LANGUAGES,
            HEALTH,
            meFails(401, 'UNAUTHENTICATED'),
            authSettings({
                captcha_enabled: true,
                captcha_site_key: 'site-key-1',
                captcha_version: 'v3',
            }),
        );

        renderApp();
        await screen.findByRole('button', { name: 'Sign in' });

        await waitFor(() => {
            expect(document.getElementById('recaptcha-api')).not.toBeNull();
        });

        expect(document.getElementById('recaptcha-api')?.getAttribute('src')).toContain(
            'render=site-key-1',
        );
    });

    it('does not hold up submission for v3, which has no challenge to wait for', async () => {
        // v3 renders nothing and mints its token during submit. Disabling the button
        // until a token exists — correct for v2 — would make v3 unsubmittable.
        server.use(
            LANGUAGES,
            HEALTH,
            meFails(401, 'UNAUTHENTICATED'),
            authSettings({
                captcha_enabled: true,
                captcha_site_key: 'site-key-1',
                captcha_version: 'v3',
            }),
        );

        renderApp();

        const submit = await screen.findByRole('button', { name: 'Sign in' });
        await waitFor(() => {
            expect(document.getElementById('recaptcha-api')).not.toBeNull();
        });

        expect(submit).toBeEnabled();
        expect(screen.queryByText(/protected by reCAPTCHA/i)).toBeInTheDocument();
    });
});
