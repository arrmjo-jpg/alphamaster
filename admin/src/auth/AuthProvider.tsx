import { createContext, use, useCallback, useEffect, useMemo, useRef, useState } from 'react';

import { ApiError } from '@/api/errors';

import * as api from './api';
import {
    isAdministrator,
    isEmailVerificationRequired,
    isMfaRequired,
    isMfaSetupRequired,
} from './contract';
import type { AuthenticatedUser } from './contract';
import { stateFromLoginFailure, stateFromMeFailure, type AuthState } from './machine';

interface AuthContextValue {
    state: AuthState;
    signIn: (credentials: api.LoginCredentials) => Promise<void>;
    submitMfaCode: (code: string) => Promise<void>;
    /** Called once enrolment has been confirmed; the credential was exchanged server-side. */
    enrolmentCompleted: () => Promise<void>;
    /** Re-asks the API rather than trusting the client's belief about verification. */
    recheckSession: () => Promise<void>;
    signOut: () => Promise<void>;
}

const AuthContext = createContext<AuthContextValue | null>(null);

/** Where a successful credential lands: authenticated, or refused for a stated reason. */
function settle(user: AuthenticatedUser): AuthState {
    if (!user.is_active) {
        return { status: 'refused', reason: 'suspended', user };
    }

    if (!isAdministrator(user)) {
        return { status: 'refused', reason: 'notAdministrator', user };
    }

    return { status: 'authenticated', user };
}

export function AuthProvider({ children }: { children: React.ReactNode }) {
    const [state, setState] = useState<AuthState>({ status: 'checking' });

    // Guards against a state update after unmount, and against a slow bootstrap
    // landing on top of a sign-in the operator has already started.
    const generation = useRef(0);

    /**
     * `null` means this attempt was superseded and must set nothing.
     *
     * An aborted request is not a failure and must not be read as one. React's strict
     * mode mounts, unmounts and remounts in development, so the first bootstrap is
     * always aborted — reporting that as "the platform is unreachable" would make
     * every development page load start with an error that is not true.
     */
    const resolveSession = useCallback(async (signal?: AbortSignal): Promise<AuthState | null> => {
        try {
            return settle(await api.currentUser(signal));
        } catch (error) {
            return signal?.aborted === true ? null : stateFromMeFailure(error);
        }
    }, []);

    useEffect(() => {
        const controller = new AbortController();
        const current = ++generation.current;

        void resolveSession(controller.signal).then((next) => {
            if (next !== null && generation.current === current) {
                setState(next);
            }
        });

        return () => controller.abort();
    }, [resolveSession]);

    const signIn = useCallback(
        async (credentials: api.LoginCredentials) => {
            const current = ++generation.current;
            setState({ status: 'authenticating' });

            let next: AuthState;

            try {
                const payload = await api.login(credentials);

                // Order follows the backend's: verification, then enrolment, then
                // challenge. Reading it in a different order here would put an
                // administrator in front of an enrolment screen for a credential that
                // cannot reach enrolment.
                if (isEmailVerificationRequired(payload)) {
                    next = { status: 'emailVerification' };
                } else if (isMfaSetupRequired(payload)) {
                    next = { status: 'mfaEnrolment' };
                } else if (isMfaRequired(payload)) {
                    next = {
                        status: 'mfaChallenge',
                        mfaToken: payload.mfa_token,
                        expiresIn: payload.expires_in,
                    };
                } else {
                    // The body carried a token. It is ignored: the same value arrived
                    // as an HttpOnly cookie, and `/auth/me` is what says who this is.
                    // No signal, so this cannot come back null.
                    next = (await resolveSession()) ?? { status: 'checking' };
                }
            } catch (error) {
                next = stateFromLoginFailure(error);
            }

            if (generation.current === current) {
                setState(next);
            }
        },
        [resolveSession],
    );

    const submitMfaCode = useCallback(
        async (code: string) => {
            if (state.status !== 'mfaChallenge') {
                return;
            }

            const current = ++generation.current;
            const { mfaToken } = state;

            try {
                await api.completeMfaChallenge(mfaToken, code);
            } catch (error) {
                if (generation.current !== current) {
                    return;
                }

                // A wrong code leaves the challenge open — the operator retries in
                // place rather than being sent back to the password form, which would
                // cost them the challenge they had already started. A challenge that
                // has expired or been exhausted is a different matter, and the screen
                // reads the error to decide which it was.
                throw error;
            }

            const next = await resolveSession();

            if (next !== null && generation.current === current) {
                setState(next);
            }
        },
        [resolveSession, state],
    );

    const enrolmentCompleted = useCallback(async () => {
        const current = ++generation.current;
        const next = await resolveSession();

        if (next !== null && generation.current === current) {
            setState(next);
        }
    }, [resolveSession]);

    const recheckSession = enrolmentCompleted;

    const signOut = useCallback(async () => {
        const current = ++generation.current;

        try {
            await api.logout();
        } catch (error) {
            // `/auth/logout` requires an access ability, so a browser holding an
            // enrolment or verification credential cannot reach it and is answered
            // 403. That is not a failure to sign out: nothing is left that can act,
            // and the next sign-in replaces the cookie. Any other error is also not
            // worth blocking on — the operator asked to leave.
            if (!(error instanceof ApiError)) {
                throw error;
            }
        }

        if (generation.current === current) {
            setState({ status: 'unauthenticated' });
        }
    }, []);

    const value = useMemo(
        () => ({ state, signIn, submitMfaCode, enrolmentCompleted, recheckSession, signOut }),
        [state, signIn, submitMfaCode, enrolmentCompleted, recheckSession, signOut],
    );

    return <AuthContext value={value}>{children}</AuthContext>;
}

export function useAuth(): AuthContextValue {
    const context = use(AuthContext);

    if (context === null) {
        throw new Error('useAuth must be used inside AuthProvider');
    }

    return context;
}

/** The signed-in administrator, for screens that only render inside a session. */
export function useCurrentUser(): AuthenticatedUser {
    const { state } = useAuth();

    if (state.status !== 'authenticated') {
        throw new Error('useCurrentUser was called outside an authenticated session');
    }

    return state.user;
}
