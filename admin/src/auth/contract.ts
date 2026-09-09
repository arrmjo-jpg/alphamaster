import type { AuthLoginResponses } from '@/api/generated';

/**
 * The four things a sign-in can answer with, named.
 *
 * The generated union already carries all four (see `LoginPayload` below, which is
 * pinned to it), but it is a union of anonymous object types with no discriminant a
 * `switch` can use. These aliases give each arm a name and a flag to narrow on, so
 * the state machine reads as the four outcomes the backend actually has rather than
 * as a chain of `in` checks.
 */

export interface AuthenticatedPayload {
    token: string;
    token_type: 'Bearer';
    abilities: string[];
}

export interface MfaRequiredPayload {
    mfa_required: boolean;
    mfa_token: string;
    expires_in: number;
}

export interface MfaSetupRequiredPayload {
    mfa_setup_required: boolean;
    enrolment_token: string;
    token_type: 'Bearer';
    abilities: string[];
}

export interface EmailVerificationRequiredPayload {
    email_verification_required: boolean;
    verification_token: string;
    token_type: 'Bearer';
    abilities: string[];
}

export type LoginPayload =
    | AuthenticatedPayload
    | MfaRequiredPayload
    | MfaSetupRequiredPayload
    | EmailVerificationRequiredPayload;

/**
 * The contract's own union, kept beside the hand-written one.
 *
 * If the backend grows a fifth outcome or renames a field, this assignment stops
 * compiling and the failure lands here rather than at runtime as an unhandled branch
 * that silently falls through to "signed in".
 */
type GeneratedLoginData = AuthLoginResponses[200]['data'];

type LoginPayloadCoversTheContract = GeneratedLoginData extends LoginPayload ? true : never;

/**
 * Exported so the assertion is evaluated rather than elided, and so an unused-local
 * rule does not delete the only thing keeping the two unions in step.
 */
export const LOGIN_PAYLOAD_MATCHES_CONTRACT: LoginPayloadCoversTheContract = true;

export function isMfaRequired(payload: LoginPayload): payload is MfaRequiredPayload {
    return 'mfa_required' in payload;
}

export function isMfaSetupRequired(payload: LoginPayload): payload is MfaSetupRequiredPayload {
    return 'mfa_setup_required' in payload;
}

export function isEmailVerificationRequired(
    payload: LoginPayload,
): payload is EmailVerificationRequiredPayload {
    return 'email_verification_required' in payload;
}

/** The identity behind the presented credential, from `/auth/me`. */
export interface AuthenticatedUser {
    id: string;
    name: string;
    email: string;
    account_type: string;
    is_active: boolean;
    email_verified: boolean;
    email_verified_at: string | null;
    abilities: string[];
    roles: string[];
    permissions: string[];
}

/** ADR 0028: what makes an account an administrator is its type, not a role. */
export const ADMIN_ACCOUNT_TYPE = 'admin';

export function isAdministrator(user: AuthenticatedUser): boolean {
    return user.account_type === ADMIN_ACCOUNT_TYPE;
}

/** The public half of the auth settings group, which the sign-in page may read. */
export interface PublicAuthSettings {
    captcha_enabled?: boolean;
    captcha_site_key?: string | null;
    /**
     * `v2` or `v3`, and the sign-in page cannot work without it.
     *
     * The two are different interactions, not styles of one: v2 is a checkbox that
     * yields a token when ticked, v3 renders nothing and mints a token on demand. A
     * site key does not say which it is, so the platform publishes it.
     */
    captcha_version?: string;
    password_min_length?: number;
}

export interface MfaEnrolment {
    type: string;
    secret?: string;
    uri?: string;
    destination?: string;
}

export interface MfaVerified {
    enabled: boolean;
    recovery_codes: string[];
    /** Present only when an enrolment credential was exchanged for a real one. */
    token?: string;
    abilities?: string[];
}
