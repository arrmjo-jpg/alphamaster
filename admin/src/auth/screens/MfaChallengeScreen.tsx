import { useState } from 'react';
import { useTranslation } from 'react-i18next';

import { ApiError, ErrorCode } from '@/api/errors';
import { sendMfaChallengeCode } from '@/auth/api';
import { useAuth } from '@/auth/AuthProvider';
import { useCountdown } from '@/auth/useCountdown';
import { Alert } from '@/ui/Alert';
import { Button } from '@/ui/Button';
import { Field } from '@/ui/Field';
import { Input } from '@/ui/Input';

import { AuthLayout } from './AuthLayout';

export interface MfaChallengeScreenProps {
    mfaToken: string;
    /** How long the challenge itself lives, from the backend. */
    expiresIn: number;
}

/**
 * The second factor.
 *
 * The challenge token is held in memory and passed back, never stored: it grants
 * nothing on its own and it must not outlive the tab. Delivery is a separate,
 * deliberate request — signing in does not send a message, because an attacker
 * holding a password must not be able to make the platform text the account owner
 * repeatedly.
 */
export function MfaChallengeScreen({ mfaToken, expiresIn }: MfaChallengeScreenProps) {
    const { t } = useTranslation();
    const { submitMfaCode, signOut } = useAuth();

    const [code, setCode] = useState('');
    const [busy, setBusy] = useState(false);
    const [sending, setSending] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [expired, setExpired] = useState(false);
    const [sentTo, setSentTo] = useState<string | null>(null);
    const [attemptsRemaining, setAttemptsRemaining] = useState<number | null>(null);
    const [cooldown, setCooldown] = useState(0);

    const remaining = useCountdown(expiresIn);
    const cooling = useCountdown(cooldown);

    const submit = async (event: React.FormEvent) => {
        event.preventDefault();
        setBusy(true);
        setError(null);

        try {
            await submitMfaCode(code);
        } catch (caught) {
            if (!(caught instanceof ApiError)) {
                throw caught;
            }

            setError(caught.message);
            setAttemptsRemaining(caught.attemptsRemaining);
            setCode('');

            // A challenge that has been thrown away cannot be retried, and leaving the
            // form live would let the operator spend their remaining attempts against
            // a token the server has already forgotten.
            if (caught.is(ErrorCode.TooManyAttempts)) {
                setExpired(true);
            }
        } finally {
            setBusy(false);
        }
    };

    const requestCode = async () => {
        setSending(true);
        setError(null);

        try {
            const { destination } = await sendMfaChallengeCode(mfaToken);
            setSentTo(destination);
        } catch (caught) {
            if (!(caught instanceof ApiError)) {
                throw caught;
            }

            setError(caught.message);

            // The backend enforces its own resend cooldown and says how long it is,
            // so the button reflects the server's answer rather than a number invented
            // here.
            if (caught.retryAfterSeconds !== null) {
                setCooldown(caught.retryAfterSeconds);
            }
        } finally {
            setSending(false);
        }
    };

    const dead = expired || remaining === 0;

    return (
        <AuthLayout
            description={t('auth.mfa.challengeDescription')}
            footer={
                <Button className="w-full" onClick={() => void signOut()} variant="ghost">
                    {t('auth.startOver')}
                </Button>
            }
            title={t('auth.mfa.challengeTitle')}
        >
            <form className="flex flex-col gap-4" noValidate onSubmit={(e) => void submit(e)}>
                {dead ? (
                    <Alert title={t('auth.mfa.expiredTitle')} tone="warning">
                        {t('auth.mfa.expiredBody')}
                    </Alert>
                ) : null}

                {!dead && error !== null ? (
                    <Alert tone="danger">
                        <p>{error}</p>
                        {attemptsRemaining !== null ? (
                            <p className="mt-1">
                                {t('auth.signIn.attemptsRemaining', { count: attemptsRemaining })}
                            </p>
                        ) : null}
                    </Alert>
                ) : null}

                {sentTo !== null ? (
                    <Alert tone="info">{t('auth.mfa.sentTo', { destination: sentTo })}</Alert>
                ) : null}

                <Field hint={t('auth.mfa.codeHint')} label={t('auth.mfa.code')} required>
                    {({ id, ...described }) => (
                        <Input
                            autoComplete="one-time-code"
                            disabled={busy || dead}
                            id={id}
                            inputMode="numeric"
                            onChange={(event) => setCode(event.target.value)}
                            value={code}
                            {...described}
                        />
                    )}
                </Field>

                <Button
                    disabled={dead || code.trim() === ''}
                    loading={busy}
                    type="submit"
                    variant="primary"
                >
                    {t('auth.mfa.verify')}
                </Button>

                <Button
                    disabled={dead || cooling > 0}
                    loading={sending}
                    onClick={() => void requestCode()}
                    type="button"
                    variant="ghost"
                >
                    {cooling > 0
                        ? t('auth.mfa.resendIn', { count: cooling })
                        : t('auth.mfa.sendCode')}
                </Button>
            </form>
        </AuthLayout>
    );
}
