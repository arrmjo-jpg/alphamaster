import { zodResolver } from '@hookform/resolvers/zod';
import { useEffect, useState } from 'react';
import { useForm } from 'react-hook-form';
import { useTranslation } from 'react-i18next';
import { z } from 'zod';

import { publicAuthSettings } from '@/auth/api';
import { useAuth } from '@/auth/AuthProvider';
import { CaptchaField, useCaptcha } from '@/auth/Captcha';
import type { PublicAuthSettings } from '@/auth/contract';
import { INCOMPLETE_SIGN_IN, PLATFORM_UNREACHABLE, type SignInFailure } from '@/auth/machine';
import { useCountdown } from '@/auth/useCountdown';
import { Alert } from '@/ui/Alert';
import { Button } from '@/ui/Button';
import { Field } from '@/ui/Field';
import { Input } from '@/ui/Input';

import { AuthCover } from './AuthCover';

// Presence only. The platform owns what a valid identifier and a valid password are,
// and a client that re-states those rules is a second copy to keep in step — and one
// that would tell an unauthenticated caller which of the two it recognised.
const schema = z.object({
    identifier: z.string().trim().min(1),
    password: z.string().min(1),
});

type FormValues = z.infer<typeof schema>;

export interface SignInScreenProps {
    busy: boolean;
    failure?: SignInFailure;
    /** Seconds the limiter is holding the form closed, or 0 when it is not. */
    lockedFor?: number;
}

export function SignInScreen({ busy, failure, lockedFor = 0 }: SignInScreenProps) {
    const { t } = useTranslation();
    const { signIn } = useAuth();

    // Two of these are not refusals at all, and are not coloured as one: an
    // abandoned sign-in and an unreachable API both leave the form usable.
    const FAILURE_TITLES: Record<string, string> = {
        [INCOMPLETE_SIGN_IN]: t('auth.incomplete.title'),
        [PLATFORM_UNREACHABLE]: t('auth.unreachable.title'),
    };

    const [settings, setSettings] = useState<PublicAuthSettings | null>(null);
    const captcha = useCaptcha(settings);

    const remaining = useCountdown(lockedFor);
    const locked = remaining > 0;

    const {
        register,
        handleSubmit,
        formState: { errors },
    } = useForm<FormValues>({ resolver: zodResolver(schema) });

    useEffect(() => {
        const controller = new AbortController();

        void publicAuthSettings(controller.signal)
            .then(setSettings)
            .catch(() => {
                // The captcha switch is unreadable, so no widget is rendered. The
                // backend still fails closed if one was required, and the refusal is
                // the ordinary one — which is what a caller with no token receives.
                setSettings({});
            });

        return () => controller.abort();
    }, []);

    // A captcha response may be spent once. Burning it after every failure is what
    // stops a second attempt from being refused for a reason nothing on screen
    // explains.
    const { reset: resetCaptcha } = captcha;

    useEffect(() => {
        if (failure !== undefined) {
            resetCaptcha();
        }
    }, [failure, resetCaptcha]);

    const submit = handleSubmit(async (values) => {
        // Obtained here rather than held in state, because that is the only shape
        // that works for both: v2 hands back the token the checkbox already produced,
        // and v3 mints a fresh one for this submission — a v3 token is scored against
        // the action it was minted for and expires in about two minutes.
        const captchaToken = await captcha.obtainToken();

        await signIn({
            identifier: values.identifier,
            password: values.password,
            ...(captchaToken !== null ? { captchaToken } : {}),
        });
    });

    return (
        <AuthCover description={t('auth.signIn.description')} title={t('auth.signIn.title')}>
            <form
                className="flex flex-col gap-4"
                noValidate
                onSubmit={(event) => void submit(event)}
            >
                {locked ? (
                    <Alert title={t('auth.lockedOut.title')} tone="danger">
                        {t('auth.lockedOut.body', { count: remaining })}
                    </Alert>
                ) : null}

                {!locked && failure !== undefined ? (
                    <Alert
                        title={FAILURE_TITLES[failure.code] ?? t('auth.signIn.refused')}
                        tone={failure.code === 'INVALID_CREDENTIALS' ? 'danger' : 'warning'}
                    >
                        <p>{failure.message}</p>
                        {failure.attemptsRemaining !== undefined ? (
                            <p className="mt-1">
                                {t('auth.signIn.attemptsRemaining', {
                                    count: failure.attemptsRemaining,
                                })}
                            </p>
                        ) : null}
                    </Alert>
                ) : null}

                <Field
                    label={t('auth.signIn.identifier')}
                    required
                    {...(errors.identifier ? { error: t('auth.signIn.identifierRequired') } : {})}
                >
                    {({ id, invalid, ...described }) => (
                        <Input
                            autoComplete="username"
                            disabled={busy || locked}
                            id={id}
                            invalid={invalid}
                            {...described}
                            {...register('identifier')}
                        />
                    )}
                </Field>

                <Field
                    label={t('auth.signIn.password')}
                    required
                    {...(errors.password ? { error: t('auth.signIn.passwordRequired') } : {})}
                >
                    {({ id, invalid, ...described }) => (
                        <Input
                            autoComplete="current-password"
                            disabled={busy || locked}
                            id={id}
                            invalid={invalid}
                            type="password"
                            {...described}
                            {...register('password')}
                        />
                    )}
                </Field>

                <CaptchaField state={captcha} />

                <Button
                    disabled={locked || !captcha.ready}
                    loading={busy}
                    type="submit"
                    variant="primary"
                >
                    {t('auth.signIn.submit')}
                </Button>
            </form>
        </AuthCover>
    );
}
