import { useState } from 'react';
import { useTranslation } from 'react-i18next';

import { ApiError } from '@/api/errors';
import { confirmPhoneNumber, sendPhoneVerificationCode } from '@/auth/api';
import { useAuth, useCurrentUser } from '@/auth/AuthProvider';
import { useCountdown } from '@/auth/useCountdown';
import { absoluteTime } from '@/lib/time';
import { Alert } from '@/ui/Alert';
import { Button } from '@/ui/Button';
import { Field } from '@/ui/Field';
import { Input } from '@/ui/Input';
import { StatusBadge } from '@/ui/StatusBadge';

/**
 * The signed-in account, as it can see itself.
 *
 * Every other workspace in this console is about the platform or about other people.
 * This one is about the viewer, which is why it carries no permission: there is
 * nothing here that could be aimed at somebody else's account, and the endpoints
 * behind it take no account identifier at all.
 *
 * It exists because phone verification had nowhere else to live. Confirming a number
 * means a person answers a message sent to it, so an administrator cannot do it on
 * somebody's behalf — the account detail panel can only ever *report* the state, and
 * a console where nobody could reach the flow would have made the whole capability a
 * column in a table.
 *
 * What is deliberately absent: the number itself cannot be changed here. The platform
 * has no self-service route that writes to an account's own identity, so the screen
 * says who does set it rather than offering a field that would need one.
 */
export function AccountScreen() {
    const { t, i18n } = useTranslation();
    const user = useCurrentUser();
    const { recheckSession } = useAuth();

    const [code, setCode] = useState('');
    const [sending, setSending] = useState(false);
    const [confirming, setConfirming] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [sentTo, setSentTo] = useState<string | null>(null);
    const [cooldown, setCooldown] = useState(0);

    const cooling = useCountdown(cooldown);
    const locale = i18n.language;

    const requestCode = async () => {
        setSending(true);
        setError(null);

        try {
            const { destination } = await sendPhoneVerificationCode();
            setSentTo(destination);
        } catch (caught) {
            if (!(caught instanceof ApiError)) {
                throw caught;
            }

            setError(caught.message);

            // The wait is the server's, not a number invented here: it is the cooldown
            // an operator configured, and the button should reflect that one.
            if (caught.retryAfterSeconds !== null) {
                setCooldown(caught.retryAfterSeconds);
            }
        } finally {
            setSending(false);
        }
    };

    const confirm = async (event: React.FormEvent) => {
        event.preventDefault();
        setConfirming(true);
        setError(null);

        try {
            await confirmPhoneNumber(code.trim());
            setCode('');
            setSentTo(null);

            // Re-ask rather than patch the local copy. The badge above is drawn from
            // the session, and the session is what the API says it is.
            await recheckSession();
        } catch (caught) {
            if (!(caught instanceof ApiError)) {
                throw caught;
            }

            setError(caught.message);
            setCode('');
        } finally {
            setConfirming(false);
        }
    };

    return (
        <div className="flex min-w-0 flex-col gap-(--section-gap)">
            <header>
                <p data-eyebrow>{t('account.eyebrow')}</p>
                <h1 className="text-(length:--text-2xl) text-(--text-primary)">
                    {t('modules.account')}
                </h1>
                <p className="mt-1 max-w-prose text-(length:--text-sm) text-(--text-secondary)">
                    {t('account.description')}
                </p>
            </header>

            <section className="flex max-w-prose flex-col gap-2 border border-(--border-default) bg-(--surface-raised) p-4">
                <p data-eyebrow>{t('account.identity')}</p>
                <p className="text-(length:--text-md) font-medium text-(--text-primary)">
                    {user.name}
                </p>
                <p className="text-(length:--text-sm) text-(--text-secondary)" data-technical>
                    {user.email}
                </p>
                <div className="mt-1 flex flex-wrap gap-1.5">
                    <StatusBadge tone={user.email_verified ? 'success' : 'warning'}>
                        {user.email_verified
                            ? t('account.emailVerified')
                            : t('account.emailUnverified')}
                    </StatusBadge>
                </div>
            </section>

            <section className="flex max-w-prose flex-col gap-3 border border-(--border-default) bg-(--surface-raised) p-4">
                <div className="flex flex-col gap-1">
                    <p data-eyebrow>{t('account.phone')}</p>
                    {user.phone === null ? (
                        <p className="text-(length:--text-sm) text-(--text-secondary)">
                            {t('account.noPhone')}
                        </p>
                    ) : (
                        <>
                            <p
                                className="text-(length:--text-md) text-(--text-primary)"
                                data-technical
                            >
                                {user.phone}
                            </p>
                            <div className="flex flex-wrap gap-1.5">
                                <StatusBadge tone={user.phone_verified ? 'success' : 'warning'}>
                                    {user.phone_verified
                                        ? t('account.phoneVerified')
                                        : t('account.phoneUnverified')}
                                </StatusBadge>
                            </div>
                        </>
                    )}
                </div>

                {user.phone !== null && user.phone_verified ? (
                    <p className="text-(length:--text-xs) text-(--text-muted)">
                        {user.phone_verified_at === null
                            ? t('account.phoneVerifiedNote')
                            : t('account.phoneVerifiedAt', {
                                  at: absoluteTime(user.phone_verified_at, locale) ?? '',
                              })}
                    </p>
                ) : null}

                {user.phone !== null && !user.phone_verified ? (
                    <form
                        className="flex flex-col gap-3 border-t border-(--border-default) pt-3"
                        noValidate
                        onSubmit={(event) => void confirm(event)}
                    >
                        <p className="text-(length:--text-sm) text-(--text-secondary)">
                            {t('account.confirmIntro')}
                        </p>

                        {error !== null ? <Alert tone="danger">{error}</Alert> : null}

                        {sentTo !== null ? (
                            <Alert tone="info">
                                {t('account.sentTo', { destination: sentTo })}
                            </Alert>
                        ) : null}

                        <div>
                            <Button
                                disabled={cooling > 0}
                                loading={sending}
                                onClick={() => void requestCode()}
                                type="button"
                                variant="secondary"
                            >
                                {cooling > 0
                                    ? t('account.sendAgainIn', { count: cooling })
                                    : t('account.sendCode')}
                            </Button>
                        </div>

                        <Field hint={t('account.codeHint')} label={t('account.code')} required>
                            {({ id, ...described }) => (
                                <Input
                                    autoComplete="one-time-code"
                                    disabled={confirming}
                                    id={id}
                                    inputMode="numeric"
                                    onChange={(event) => setCode(event.target.value)}
                                    value={code}
                                    {...described}
                                />
                            )}
                        </Field>

                        <div>
                            <Button
                                disabled={code.trim() === ''}
                                loading={confirming}
                                type="submit"
                                variant="primary"
                            >
                                {t('account.confirm')}
                            </Button>
                        </div>
                    </form>
                ) : null}

                {/* Said plainly rather than offered as a disabled field: the platform
                    has no route that lets an account rewrite its own identity, and a
                    control that looked available would be a promise it cannot keep. */}
                <p className="text-(length:--text-xs) text-(--text-muted)">
                    {t('account.numberSetByAdministrator')}
                </p>
            </section>
        </div>
    );
}
