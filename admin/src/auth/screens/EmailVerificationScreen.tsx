import { useState } from 'react';
import { useTranslation } from 'react-i18next';

import { ApiError } from '@/api/errors';
import { sendVerificationEmail } from '@/auth/api';
import { useAuth } from '@/auth/AuthProvider';
import { useCountdown } from '@/auth/useCountdown';
import { Alert } from '@/ui/Alert';
import { Button } from '@/ui/Button';

import { AuthCover } from './AuthCover';

/**
 * Proving control of the address before anything else proceeds.
 *
 * The link goes to a mail client and lands on the API, which answers JSON — the
 * backend is API-only (ADR 0001) and the verification route is a signed GET it
 * handles itself. So this screen cannot watch for the click; it offers the two things
 * that are actually useful, sending the link and starting again once it has been
 * followed.
 *
 * Starting again is not a detour. The credential issued for this flow carries only
 * `email:verify`, which reaches neither `/auth/me` nor `/auth/logout`, so there is no
 * way to upgrade it in place — signing in again is what mints the real session, and
 * the new cookie replaces this one.
 */
export function EmailVerificationScreen() {
    const { t } = useTranslation();
    const { signOut } = useAuth();

    const [busy, setBusy] = useState(false);
    const [sent, setSent] = useState(false);
    const [alreadyVerified, setAlreadyVerified] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [cooldown, setCooldown] = useState(0);

    const cooling = useCountdown(cooldown);

    const send = async () => {
        setBusy(true);
        setError(null);

        try {
            const { email_verified: verified } = await sendVerificationEmail();
            setAlreadyVerified(verified);
            setSent(!verified);
        } catch (caught) {
            if (!(caught instanceof ApiError)) {
                throw caught;
            }

            setError(caught.message);

            // The platform throttles this and says for how long, so the button is
            // disabled for the server's answer rather than a guess made here.
            if (caught.retryAfterSeconds !== null) {
                setCooldown(caught.retryAfterSeconds);
            }
        } finally {
            setBusy(false);
        }
    };

    return (
        <AuthCover description={t('auth.verify.description')} title={t('auth.verify.title')}>
            <div className="flex flex-col gap-4">
                {error !== null ? <Alert tone="danger">{error}</Alert> : null}

                {alreadyVerified ? <Alert tone="success">{t('auth.verify.already')}</Alert> : null}

                {sent ? <Alert tone="info">{t('auth.verify.sent')}</Alert> : null}

                <Button
                    disabled={cooling > 0}
                    loading={busy}
                    onClick={() => void send()}
                    variant="secondary"
                >
                    {cooling > 0
                        ? t('auth.verify.resendIn', { count: cooling })
                        : t('auth.verify.send')}
                </Button>

                <Button onClick={() => void signOut()} variant="primary">
                    {t('auth.verify.continue')}
                </Button>
            </div>
        </AuthCover>
    );
}
