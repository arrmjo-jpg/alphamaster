import { useTranslation } from 'react-i18next';

import { useAuth } from './AuthProvider';
import { EmailVerificationScreen } from './screens/EmailVerificationScreen';
import { MfaChallengeScreen } from './screens/MfaChallengeScreen';
import { MfaEnrolmentScreen } from './screens/MfaEnrolmentScreen';
import { RefusedScreen } from './screens/RefusedScreen';
import { SignInScreen } from './screens/SignInScreen';

/**
 * One place decides what an unauthenticated browser sees.
 *
 * Every state has a screen and the switch is exhaustive, so a state added later
 * cannot fall through to the application: TypeScript reports the missing branch at
 * the `never` below rather than the browser rendering an admin console to somebody
 * who is halfway through signing in.
 */
export function AuthGate({ children }: { children: React.ReactNode }) {
    const { state } = useAuth();

    switch (state.status) {
        case 'checking':
            return <Splash />;

        case 'unauthenticated':
            return (
                <SignInScreen busy={false} {...(state.failure ? { failure: state.failure } : {})} />
            );

        case 'authenticating':
            return <SignInScreen busy />;

        case 'lockedOut':
            return <SignInScreen busy={false} lockedFor={state.retryAfter} />;

        case 'mfaChallenge':
            return <MfaChallengeScreen expiresIn={state.expiresIn} mfaToken={state.mfaToken} />;

        case 'mfaEnrolment':
            return <MfaEnrolmentScreen />;

        case 'emailVerification':
            return <EmailVerificationScreen />;

        case 'refused':
            return <RefusedScreen reason={state.reason} />;

        case 'authenticated':
            return children;

        default: {
            const exhaustive: never = state;

            throw new Error(`Unhandled authentication state: ${JSON.stringify(exhaustive)}`);
        }
    }
}

/**
 * The first paint, while the cookie in the browser is being checked.
 *
 * Deliberately almost nothing. A skeleton of an application the viewer may turn out
 * not to be allowed into would be a worse answer than a quiet one, and this normally
 * lasts one request.
 */
function Splash() {
    const { t } = useTranslation();

    return (
        <div className="flex min-h-dvh items-center justify-center bg-(--surface-canvas)">
            <p aria-live="polite" className="text-(--text-muted)">
                {t('state.loading')}
            </p>
        </div>
    );
}
