import { fetchData, request } from '@/api/client';

import type {
    AuthenticatedUser,
    LoginPayload,
    MfaEnrolment,
    MfaVerified,
    PublicAuthSettings,
} from './contract';

/**
 * The auth endpoints, and nothing about them that is not in the contract.
 *
 * Every one of these goes through the shared client, so the session cookie is
 * attached the same way it is everywhere else and no credential is handled here.
 * The tokens the backend returns in these bodies are deliberately ignored: the same
 * value arrives as an HttpOnly cookie, which is the copy the browser will use, and
 * reading the body's copy into memory would recreate exactly the exposure ADR 0042
 * removed.
 */

export interface LoginCredentials {
    identifier: string;
    password: string;
    captchaToken?: string;
}

export async function login(credentials: LoginCredentials): Promise<LoginPayload> {
    return fetchData<LoginPayload>('/auth/login', {
        method: 'POST',
        body: {
            identifier: credentials.identifier,
            password: credentials.password,
            ...(credentials.captchaToken !== undefined
                ? { captcha_token: credentials.captchaToken }
                : {}),
        },
    });
}

/** Completing a challenge sets the real session cookie; the body's token is ignored. */
export async function completeMfaChallenge(mfaToken: string, code: string): Promise<void> {
    await request('/auth/mfa/challenge', {
        method: 'POST',
        body: { mfa_token: mfaToken, code },
    });
}

export async function sendMfaChallengeCode(mfaToken: string): Promise<{ destination: string }> {
    return fetchData<{ destination: string }>('/auth/mfa/challenge/send', {
        method: 'POST',
        body: { mfa_token: mfaToken },
    });
}

export async function currentUser(signal?: AbortSignal): Promise<AuthenticatedUser> {
    return fetchData<AuthenticatedUser>('/auth/me', { ...(signal ? { signal } : {}) });
}

export async function logout(): Promise<void> {
    await request('/auth/logout', { method: 'POST' });
}

export async function beginMfaEnrolment(type: string, phone?: string): Promise<MfaEnrolment> {
    return fetchData<MfaEnrolment>('/auth/mfa/enrol', {
        method: 'POST',
        body: { type, ...(phone !== undefined ? { phone } : {}) },
    });
}

/**
 * Confirm enrolment.
 *
 * For an administrator who arrived on an enrolment credential this is also the
 * exchange: the backend burns that credential and sets the real session cookie in the
 * same response (ADR 0013). The recovery codes come back once and are never
 * retrievable again, which is why the screen showing them refuses to move on until
 * they have been acknowledged.
 */
export async function confirmMfaEnrolment(type: string, code: string): Promise<MfaVerified> {
    return fetchData<MfaVerified>('/auth/mfa/verify', {
        method: 'POST',
        body: { type, code },
    });
}

export async function sendVerificationEmail(): Promise<{ email_verified: boolean }> {
    return fetchData<{ email_verified: boolean }>('/auth/email/verify/send', { method: 'POST' });
}

/**
 * The public part of the auth settings group.
 *
 * Unauthenticated by design: the sign-in page has to know whether to render a captcha
 * before anyone has signed in. It carries the switch and the site key and nothing
 * else — the secret lives on the provider row and never leaves the server.
 */
export async function publicAuthSettings(signal?: AbortSignal): Promise<PublicAuthSettings> {
    return fetchData<PublicAuthSettings>('/settings/auth', { ...(signal ? { signal } : {}) });
}
