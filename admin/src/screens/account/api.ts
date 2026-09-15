import { fetchData, request } from '@/api/client';
import type {
    AuthMfaStatusResponses,
    ProfileAvatarStoreResponses,
    ProfileShowResponses,
} from '@/api/generated';

/**
 * The signed-in account's own profile, mapped onto what the platform has (ADR 0051 §4, ADR 0057).
 *
 * Every call acts on the caller. None takes an account identifier, so nothing here can be aimed
 * at somebody else's account however it is called — the same property the endpoints have.
 */

export type Profile = ProfileShowResponses[200]['data'];
export type AvatarAccepted = ProfileAvatarStoreResponses[201]['data'];
export type MfaStatus = AuthMfaStatusResponses[200]['data'];

/** What the account holder decides about themselves. Only the keys sent change. */
export interface ProfileChanges {
    name?: string;
    phone?: string | null;
    preferred_locale?: string | null;
    bio?: string | null;
}

export interface PasswordChange {
    current_password?: string;
    password: string;
    password_confirmation: string;
}

export async function profile(signal?: AbortSignal): Promise<Profile> {
    return fetchData<Profile>('/profile', { ...(signal ? { signal } : {}) });
}

export async function updateProfile(body: ProfileChanges): Promise<Profile> {
    return fetchData<Profile>('/profile', { method: 'PATCH', body });
}

/** Every other session is signed out; this one is kept. */
export async function changePassword(body: PasswordChange): Promise<void> {
    await request('/profile/password', { method: 'PUT', body });
}

export async function uploadAvatar(file: File): Promise<AvatarAccepted> {
    const body = new FormData();

    body.append('file', file, file.name);

    return fetchData<AvatarAccepted>('/profile/avatar', { method: 'POST', formData: body });
}

export async function removeAvatar(): Promise<void> {
    await request('/profile/avatar', { method: 'DELETE' });
}

export async function mfaStatus(signal?: AbortSignal): Promise<MfaStatus> {
    return fetchData<MfaStatus>('/auth/mfa', { ...(signal ? { signal } : {}) });
}

/**
 * Turn the second factor off with a current code or an unused recovery code.
 *
 * For an administrator the platform also signs out every session, this one included, and asks
 * for enrolment again at the next sign-in — the answer says so in `tokens_revoked`.
 */
export async function disableMfa(code: string): Promise<{ enabled: boolean; tokens_revoked?: boolean }> {
    return fetchData<{ enabled: boolean; tokens_revoked?: boolean }>('/auth/mfa', {
        method: 'DELETE',
        body: { code },
    });
}
