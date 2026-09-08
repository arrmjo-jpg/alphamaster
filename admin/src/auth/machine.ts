import { ApiError, ErrorCode } from '@/api/errors';

import type { AuthenticatedUser } from './contract';

/**
 * Nine states, because signing in has nine outcomes worth rendering differently.
 *
 * Collapsing any of them costs the operator the one thing they need at that moment:
 * a locked-out account is not a wrong password, an account awaiting verification is
 * not one awaiting enrolment, and an account that is simply not an administrator is
 * neither of those. Each state below names what the screen must show and carries
 * exactly the data that screen needs.
 */
export type AuthState =
    /** Asking `/auth/me` whether the cookie in the browser is still a session. */
    | { status: 'checking' }
    /** No session. The sign-in form, optionally with the reason the last try failed. */
    | { status: 'unauthenticated'; failure?: SignInFailure }
    /** A sign-in is in flight. The form stays visible and disabled. */
    | { status: 'authenticating' }
    /** The limiter has tripped. The form is unusable until the countdown expires. */
    | { status: 'lockedOut'; retryAfter: number }
    /** Password accepted, second factor outstanding. `mfaToken` grants nothing alone. */
    | { status: 'mfaChallenge'; mfaToken: string; expiresIn: number }
    /** An administrator with no second factor yet. Enrolment is not optional here. */
    | { status: 'mfaEnrolment' }
    /** The address has not been proved. Nothing else proceeds until it is. */
    | { status: 'emailVerification' }
    /** A full session, and an administrator. */
    | { status: 'authenticated'; user: AuthenticatedUser }
    /** Authenticated as someone this application is not for. */
    | { status: 'refused'; reason: RefusalReason; user?: AuthenticatedUser };

export type RefusalReason = 'suspended' | 'notAdministrator';

/** Why the last sign-in did not succeed, in the form the screen renders. */
export interface SignInFailure {
    code: string;
    message: string;
    /** From the backend, so the countdown is a fact rather than a guess. */
    attemptsRemaining?: number;
}

/**
 * A sign-in that never completed, seen on a later page load.
 *
 * `/auth/me` requires an access ability. A browser holding an enrolment or
 * verification cookie therefore gets 403 FORBIDDEN there — while a suspended account
 * gets 403 ACCOUNT_SUSPENDED, and a non-administrator gets 200 with `account_type`
 * of `user`. So the three are distinguishable, and this is the first: a partial
 * credential from a flow that was abandoned. It cannot be resumed, because the
 * credential's scope does not reach any endpoint that would say where it stopped, and
 * probing enrolment to find out would send the account holder an SMS. Signing in
 * again reissues it.
 */
export const INCOMPLETE_SIGN_IN = 'INCOMPLETE_SIGN_IN';

/**
 * The platform could not be reached at all.
 *
 * Distinct from being signed out, and it has to be: the browser holds a cookie that
 * may well still be a session, and telling the operator their credentials are the
 * problem would send them looking in the wrong place. The sign-in form is still shown
 * — there is nothing else useful to show — with this as the reason.
 */
export const PLATFORM_UNREACHABLE = 'PLATFORM_UNREACHABLE';

/**
 * What a failed `/auth/me` means for the machine.
 *
 * Everything lands somewhere. An earlier version rethrew anything it did not
 * recognise, and a page load with the API down then hung on the loading splash
 * forever: the rejection had nowhere to go, no state was ever set, and the operator
 * was left looking at the word "Loading" with no way to act. A bootstrap that cannot
 * fail visibly is worse than one that fails.
 */
export function stateFromMeFailure(error: unknown): AuthState {
    if (!(error instanceof ApiError)) {
        throw error;
    }

    if (error.isUnauthenticated) {
        return { status: 'unauthenticated' };
    }

    if (error.is(ErrorCode.AccountSuspended)) {
        return { status: 'refused', reason: 'suspended' };
    }

    if (error.isForbidden) {
        return {
            status: 'unauthenticated',
            failure: { code: INCOMPLETE_SIGN_IN, message: error.message },
        };
    }

    return {
        status: 'unauthenticated',
        failure: { code: PLATFORM_UNREACHABLE, message: error.message },
    };
}

/** What a failed `/auth/login` means for the machine. */
export function stateFromLoginFailure(error: unknown): AuthState {
    if (!(error instanceof ApiError)) {
        throw error;
    }

    if (error.is(ErrorCode.TooManyAttempts)) {
        return { status: 'lockedOut', retryAfter: error.retryAfterSeconds ?? 60 };
    }

    if (error.is(ErrorCode.AccountSuspended)) {
        return { status: 'refused', reason: 'suspended' };
    }

    return {
        status: 'unauthenticated',
        failure: {
            code: error.code,
            message: error.message,
            ...(error.attemptsRemaining !== null
                ? { attemptsRemaining: error.attemptsRemaining }
                : {}),
        },
    };
}
