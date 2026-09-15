import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';

import { fetchData } from '@/api/client';
import { ApiError } from '@/api/errors';
import { confirmPhoneNumber, sendPhoneVerificationCode } from '@/auth/api';
import { useAuth, useCurrentUser } from '@/auth/AuthProvider';
import { isAdministrator } from '@/auth/contract';
import { useCountdown } from '@/auth/useCountdown';
import { absoluteTime } from '@/lib/time';
import {
    changePassword,
    disableMfa,
    mfaStatus,
    profile as fetchProfile,
    removeAvatar,
    updateProfile,
    uploadAvatar,
    type Profile,
    type ProfileChanges,
} from '@/screens/account/api';
import { Alert } from '@/ui/Alert';
import { Button } from '@/ui/Button';
import { Field } from '@/ui/Field';
import { Input } from '@/ui/Input';
import { StatusBadge } from '@/ui/StatusBadge';

/** The avatar rule's own ceiling, from `StoreAvatarRequest`: `max:5120` kilobytes. */
const MAX_PICTURE_BYTES = 5120 * 1024;

interface LanguageOption {
    code: string;
    native_name: string;
}

const SECTION =
    'flex max-w-prose flex-col gap-3 border border-(--border-default) bg-(--surface-raised) p-4';

/**
 * The labels of the confirmed methods. The contract publishes the options as untyped entries, so
 * each is read for the `{ value, label }` shape the platform sends and skipped if it is not.
 */
function methodLabels(options: unknown[]): string[] {
    return options.flatMap((option) =>
        typeof option === 'object' &&
        option !== null &&
        'label' in option &&
        typeof option.label === 'string'
            ? [option.label]
            : [],
    );
}

/** The first message a refused request carries for one of its fields, or its own message. */
function refusal(error: unknown, field?: string): string | null {
    if (!(error instanceof ApiError)) {
        return null;
    }

    return (
        (field === undefined ? undefined : error.validationDetails?.[field]?.[0]) ?? error.message
    );
}

/**
 * The signed-in account, as it sees and manages itself (ADR 0051 §4, ADR 0057).
 *
 * Every other workspace is about the platform or about other people; this one is about the
 * viewer, which is why it carries no permission. Nothing here can be aimed at somebody else's
 * account: every endpoint behind it acts on the caller and takes no account identifier.
 *
 * It is not the team directory. A team member is content a site shows; this is an account that
 * signs in. The picture is the same kind of thing in both — a public image from the media
 * capability — and nothing else is shared.
 *
 * An administrator's address is shown and not edited: account management changes it, because an
 * unverified administrator is stopped at sign-in and a self-service change would be a way to lock
 * the account out (ADR 0051 §4).
 */
export function AccountScreen() {
    const { t } = useTranslation();
    const user = useCurrentUser();

    const profile = useQuery({
        queryKey: ['account-profile'],
        queryFn: ({ signal }) => fetchProfile(signal),
    });

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

            <PictureSection />

            <section className={SECTION}>
                <p data-eyebrow>{t('account.identity')}</p>
                <p className="text-(length:--text-md) font-medium text-(--text-primary)">
                    {user.name}
                </p>
                <p className="text-(length:--text-sm) text-(--text-secondary)" data-technical>
                    {user.email}
                </p>
                <div className="flex flex-wrap gap-1.5">
                    <StatusBadge tone={user.email_verified ? 'success' : 'warning'}>
                        {user.email_verified
                            ? t('account.emailVerified')
                            : t('account.emailUnverified')}
                    </StatusBadge>
                </div>
                {isAdministrator(user) ? (
                    <p className="text-(length:--text-xs) text-(--text-muted)">
                        {t('account.emailManaged')}
                    </p>
                ) : null}
            </section>

            {profile.isError ? (
                <Alert tone="danger">{t('account.profileUnavailable')}</Alert>
            ) : null}
            {profile.data !== undefined ? (
                <ProfileSection key={profile.data.id} profile={profile.data} />
            ) : null}

            <PhoneSection />

            {profile.data !== undefined ? (
                <PasswordSection hasPassword={profile.data.has_password} />
            ) : null}

            <MfaSection />
        </div>
    );
}

function PictureSection() {
    const { t } = useTranslation();
    const user = useCurrentUser();
    const { recheckSession } = useAuth();
    const queryClient = useQueryClient();
    const input = useRef<HTMLInputElement>(null);
    const [tooLarge, setTooLarge] = useState(false);

    const settle = async (): Promise<void> => {
        await queryClient.invalidateQueries({ queryKey: ['account-profile'] });
        // The top bar draws the picture from the session, so the session is re-asked.
        await recheckSession();
    };

    const upload = useMutation({ mutationFn: uploadAvatar, onSuccess: settle });
    const remove = useMutation({ mutationFn: removeAvatar, onSuccess: settle });

    const url = user.avatar_url ?? null;

    const choose = (file: File | undefined): void => {
        if (input.current !== null) {
            input.current.value = '';
        }

        if (file === undefined) {
            return;
        }

        remove.reset();
        setTooLarge(file.size > MAX_PICTURE_BYTES);

        if (file.size <= MAX_PICTURE_BYTES) {
            upload.mutate(file);
        }
    };

    return (
        <section className={SECTION}>
            <p data-eyebrow>{t('account.picture')}</p>
            <div className="flex flex-wrap items-center gap-4">
                {url !== null ? (
                    <img
                        alt={t('account.picture')}
                        className="size-20 shrink-0 object-cover"
                        src={url}
                    />
                ) : (
                    <span
                        aria-hidden
                        className="inline-flex size-20 shrink-0 items-center justify-center bg-(--action-primary-subtle) text-(length:--text-lg) font-bold text-(--text-brand)"
                    >
                        {user.name.slice(0, 1).toUpperCase()}
                    </span>
                )}
                <div className="flex flex-wrap gap-2">
                    <input
                        accept="image/jpeg,image/png,image/webp"
                        aria-label={t('account.uploadPicture')}
                        className="hidden"
                        onChange={(event) => choose(event.target.files?.[0])}
                        ref={input}
                        type="file"
                    />
                    <Button
                        loading={upload.isPending}
                        onClick={() => input.current?.click()}
                        size="sm"
                        type="button"
                        variant="secondary"
                    >
                        {url === null ? t('account.uploadPicture') : t('account.replacePicture')}
                    </Button>
                    {url !== null ? (
                        <Button
                            loading={remove.isPending}
                            onClick={() => remove.mutate()}
                            size="sm"
                            type="button"
                            variant="ghost"
                        >
                            {t('account.removePicture')}
                        </Button>
                    ) : null}
                </div>
            </div>
            <p className="text-(length:--text-xs) text-(--text-muted)">
                {t('account.pictureHint')}
            </p>
            {tooLarge ? <Alert tone="danger">{t('account.pictureTooLarge')}</Alert> : null}
            {upload.isSuccess && upload.data.avatar_url === null ? (
                <Alert tone="info">{t('account.pictureProcessing')}</Alert>
            ) : null}
            {upload.error !== null ? (
                <Alert tone="danger">{refusal(upload.error, 'file')}</Alert>
            ) : null}
            {remove.error !== null ? <Alert tone="danger">{refusal(remove.error)}</Alert> : null}
        </section>
    );
}

function ProfileSection({ profile }: { profile: Profile }) {
    const { t } = useTranslation();
    const { recheckSession } = useAuth();
    const queryClient = useQueryClient();

    const languages = useQuery({
        queryKey: ['account-languages'],
        queryFn: ({ signal }) => fetchData<LanguageOption[]>('/languages', { signal }),
    });

    const [name, setName] = useState(profile.name ?? '');
    const [bio, setBio] = useState(profile.bio ?? '');
    const [phone, setPhone] = useState(profile.phone ?? '');
    const [preferred, setPreferred] = useState(profile.preferred_locale ?? '');

    const changes: ProfileChanges = {};

    if (name.trim() !== (profile.name ?? '')) {
        changes.name = name.trim();
    }

    if (bio !== (profile.bio ?? '')) {
        changes.bio = bio.trim() === '' ? null : bio;
    }

    if (phone.trim() !== (profile.phone ?? '')) {
        changes.phone = phone.trim() === '' ? null : phone.trim();
    }

    if (preferred !== (profile.preferred_locale ?? '')) {
        changes.preferred_locale = preferred === '' ? null : preferred;
    }

    const dirty = Object.keys(changes).length > 0;

    const save = useMutation({
        mutationFn: () => updateProfile(changes),
        onSuccess: async (next) => {
            queryClient.setQueryData(['account-profile'], next);
            // Name and number are drawn from the session elsewhere in the console.
            await recheckSession();
        },
    });

    return (
        <form
            className={SECTION}
            noValidate
            onSubmit={(event) => {
                event.preventDefault();
                save.mutate();
            }}
        >
            <p data-eyebrow>{t('account.profile')}</p>

            <Field label={t('account.name')} required>
                {({ id, 'aria-describedby': describedBy }) => (
                    <Input
                        aria-describedby={describedBy}
                        autoComplete="name"
                        id={id}
                        onChange={(event) => {
                            save.reset();
                            setName(event.target.value);
                        }}
                        value={name}
                    />
                )}
            </Field>

            <Field hint={t('account.bioHint')} label={t('account.bio')}>
                {({ id, 'aria-describedby': describedBy }) => (
                    <textarea
                        aria-describedby={describedBy}
                        className="min-h-20 border border-(--border-default) bg-(--surface-default) px-2 py-1.5 text-(length:--text-sm) text-(--text-primary)"
                        id={id}
                        maxLength={500}
                        onChange={(event) => {
                            save.reset();
                            setBio(event.target.value);
                        }}
                        value={bio}
                    />
                )}
            </Field>

            <Field hint={t('account.phoneHint')} label={t('account.phone')}>
                {({ id, 'aria-describedby': describedBy }) => (
                    <Input
                        aria-describedby={describedBy}
                        autoComplete="tel"
                        data-technical
                        dir="ltr"
                        id={id}
                        inputMode="tel"
                        onChange={(event) => {
                            save.reset();
                            setPhone(event.target.value);
                        }}
                        value={phone}
                    />
                )}
            </Field>

            <Field hint={t('account.preferredLocaleHint')} label={t('account.preferredLocale')}>
                {({ id, 'aria-describedby': describedBy }) => (
                    <select
                        aria-describedby={describedBy}
                        className="h-9 border border-(--border-default) bg-(--surface-default) px-2 text-(length:--text-sm) text-(--text-primary)"
                        id={id}
                        onChange={(event) => {
                            save.reset();
                            setPreferred(event.target.value);
                        }}
                        value={preferred}
                    >
                        <option value="">{t('account.noPreference')}</option>
                        {(languages.data ?? []).map((language) => (
                            <option key={language.code} value={language.code}>
                                {language.native_name}
                            </option>
                        ))}
                    </select>
                )}
            </Field>

            {save.error !== null ? (
                <Alert tone="danger">{refusal(save.error, Object.keys(changes)[0])}</Alert>
            ) : null}

            <div className="flex items-center gap-3">
                <Button
                    disabled={!dirty || name.trim() === ''}
                    loading={save.isPending}
                    type="submit"
                    variant="primary"
                >
                    {t('account.save')}
                </Button>
                {save.isSuccess && !dirty ? (
                    <span className="text-(length:--text-xs) text-(--state-success-text)">
                        {t('account.saved')}
                    </span>
                ) : null}
            </div>
        </form>
    );
}

function PhoneSection() {
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
        <section className={SECTION}>
            <div className="flex flex-col gap-1">
                <p data-eyebrow>{t('account.phone')}</p>
                {user.phone === null ? (
                    <p className="text-(length:--text-sm) text-(--text-secondary)">
                        {t('account.noPhone')}
                    </p>
                ) : (
                    <>
                        <p className="text-(length:--text-md) text-(--text-primary)" data-technical>
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
                        <Alert tone="info">{t('account.sentTo', { destination: sentTo })}</Alert>
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
        </section>
    );
}

function PasswordSection({ hasPassword }: { hasPassword: boolean }) {
    const { t } = useTranslation();
    const queryClient = useQueryClient();

    const [current, setCurrent] = useState('');
    const [next, setNext] = useState('');
    const [repeat, setRepeat] = useState('');

    const mismatch = repeat !== '' && next !== repeat;

    const change = useMutation({
        mutationFn: () =>
            changePassword({
                ...(hasPassword ? { current_password: current } : {}),
                password: next,
                password_confirmation: repeat,
            }),
        onSuccess: async () => {
            setCurrent('');
            setNext('');
            setRepeat('');
            await queryClient.invalidateQueries({ queryKey: ['account-profile'] });
        },
    });

    const ready = next !== '' && repeat !== '' && !mismatch && (!hasPassword || current !== '');

    const edit =
        (setter: (value: string) => void) => (event: React.ChangeEvent<HTMLInputElement>) => {
            change.reset();
            setter(event.target.value);
        };

    return (
        <form
            className={SECTION}
            noValidate
            onSubmit={(event) => {
                event.preventDefault();
                change.mutate();
            }}
        >
            <p data-eyebrow>{t('account.password')}</p>
            <p className="text-(length:--text-xs) text-(--text-muted)">
                {t('account.passwordHint')}
            </p>

            {hasPassword ? (
                <Field label={t('account.currentPassword')} required>
                    {({ id, 'aria-describedby': describedBy }) => (
                        <Input
                            aria-describedby={describedBy}
                            autoComplete="current-password"
                            id={id}
                            onChange={edit(setCurrent)}
                            type="password"
                            value={current}
                        />
                    )}
                </Field>
            ) : null}

            <Field label={t('account.newPassword')} required>
                {({ id, 'aria-describedby': describedBy }) => (
                    <Input
                        aria-describedby={describedBy}
                        autoComplete="new-password"
                        id={id}
                        onChange={edit(setNext)}
                        type="password"
                        value={next}
                    />
                )}
            </Field>

            <Field label={t('account.repeatPassword')} required>
                {({ id, 'aria-describedby': describedBy }) => (
                    <Input
                        aria-describedby={describedBy}
                        autoComplete="new-password"
                        id={id}
                        onChange={edit(setRepeat)}
                        type="password"
                        value={repeat}
                    />
                )}
            </Field>

            {mismatch ? <Alert tone="danger">{t('account.passwordMismatch')}</Alert> : null}
            {change.error !== null ? (
                <Alert tone="danger">
                    {refusal(change.error, 'current_password') ?? refusal(change.error, 'password')}
                </Alert>
            ) : null}
            {change.isSuccess ? <Alert tone="info">{t('account.passwordChanged')}</Alert> : null}

            <div>
                <Button
                    disabled={!ready}
                    loading={change.isPending}
                    type="submit"
                    variant="primary"
                >
                    {hasPassword ? t('account.changePassword') : t('account.setPassword')}
                </Button>
            </div>
        </form>
    );
}

function MfaSection() {
    const { t } = useTranslation();
    const user = useCurrentUser();
    const { recheckSession } = useAuth();
    const queryClient = useQueryClient();
    const [code, setCode] = useState('');

    const status = useQuery({
        queryKey: ['account-mfa'],
        queryFn: ({ signal }) => mfaStatus(signal),
    });

    const administrator = isAdministrator(user);

    const disable = useMutation({
        mutationFn: () => disableMfa(code.trim()),
        onSuccess: async (result) => {
            setCode('');

            if (result.tokens_revoked === true) {
                // Every session is gone, this one included; re-asking lands on sign-in.
                await recheckSession();

                return;
            }

            await queryClient.invalidateQueries({ queryKey: ['account-mfa'] });
        },
    });

    return (
        <section className={SECTION}>
            <p data-eyebrow>{t('account.mfa')}</p>

            {status.isError ? <Alert tone="danger">{t('account.mfaUnavailable')}</Alert> : null}

            {status.data !== undefined ? (
                <>
                    <div className="flex flex-wrap items-center gap-2">
                        <StatusBadge tone={status.data.enabled ? 'success' : 'warning'}>
                            {status.data.enabled ? t('account.mfaOn') : t('account.mfaOff')}
                        </StatusBadge>
                        {status.data.enabled && status.data.methods_options.length > 0 ? (
                            <span className="text-(length:--text-sm) text-(--text-secondary)">
                                {t('account.mfaMethods', {
                                    methods: methodLabels(status.data.methods_options).join(
                                        t('list.separator'),
                                    ),
                                })}
                            </span>
                        ) : null}
                    </div>

                    {status.data.enabled ? (
                        <p className="text-(length:--text-xs) text-(--text-muted)">
                            {t('account.mfaRecoveryRemaining', {
                                count: status.data.recovery_codes_remaining,
                            })}
                        </p>
                    ) : (
                        <p className="text-(length:--text-xs) text-(--text-muted)">
                            {t('account.mfaEnrolAtSignIn')}
                        </p>
                    )}

                    {administrator ? (
                        <p className="text-(length:--text-xs) text-(--text-muted)">
                            {t('account.mfaRequired')}
                        </p>
                    ) : null}

                    {status.data.enabled ? (
                        <form
                            className="flex flex-col gap-3 border-t border-(--border-default) pt-3"
                            noValidate
                            onSubmit={(event) => {
                                event.preventDefault();
                                disable.mutate();
                            }}
                        >
                            <p className="text-(length:--text-sm) text-(--text-secondary)">
                                {t('account.mfaDisableIntro')}
                            </p>
                            {administrator ? (
                                <Alert tone="info">{t('account.mfaDisableAdministrator')}</Alert>
                            ) : null}

                            <Field label={t('account.mfaCode')} required>
                                {({ id, 'aria-describedby': describedBy }) => (
                                    <Input
                                        aria-describedby={describedBy}
                                        autoComplete="one-time-code"
                                        id={id}
                                        onChange={(event) => {
                                            disable.reset();
                                            setCode(event.target.value);
                                        }}
                                        value={code}
                                    />
                                )}
                            </Field>

                            {disable.error !== null ? (
                                <Alert tone="danger">{refusal(disable.error, 'code')}</Alert>
                            ) : null}

                            <div>
                                <Button
                                    disabled={code.trim() === ''}
                                    loading={disable.isPending}
                                    type="submit"
                                    variant="danger"
                                >
                                    {t('account.mfaDisable')}
                                </Button>
                            </div>
                        </form>
                    ) : null}
                </>
            ) : null}
        </section>
    );
}
