import { useMutation, useQueryClient } from '@tanstack/react-query';
import { X } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';

import { ApiError } from '@/api/errors';
import { useDirection } from '@/shell/DirectionProvider';
import { Alert } from '@/ui/Alert';
import { Button } from '@/ui/Button';
import { Checkbox } from '@/ui/Checkbox';
import { Field } from '@/ui/Field';
import { Input } from '@/ui/Input';

import { createUser, updateUser, type AdminUser } from './api';

export interface UserFormProps {
    /** Null while a new account is being composed. */
    account: AdminUser | null;
    onClose: () => void;
    onSaved: (account: AdminUser) => void;
}

interface Draft {
    name: string;
    email: string;
    phone: string;
    preferredLocale: string;
    password: string;
    passwordConfirmation: string;
    active: boolean;
}

function draftFrom(account: AdminUser | null): Draft {
    return {
        name: account?.name ?? '',
        email: account?.email ?? '',
        phone: account?.phone ?? '',
        preferredLocale: '',
        password: '',
        passwordConfirmation: '',
        active: account?.is_active ?? true,
    };
}

/**
 * Who an account is — created, or corrected.
 *
 * Identity only, in both modes, because that is the boundary the platform draws. What
 * an account may do is changed by promotion, by role synchronisation and by
 * activation, each its own operation behind its own permission; a form that also set
 * standing would let one permission do the work of three.
 *
 * Creating always creates a regular account. `account_type` is not a field the
 * endpoint takes and promotion remains the only route across the administrative
 * boundary, so an administrator is made in two deliberate steps rather than one
 * checkbox. The form says so instead of leaving the absence to be discovered.
 *
 * There is no password field on an edit. Setting another person's password is a
 * credential-reset primitive and this platform has no reset flow to make it part of —
 * an endpoint letting one administrator silently take over another's sign-in is not a
 * profile edit, so neither the API nor this form offers one.
 */
export function UserForm({ account, onClose, onSaved }: UserFormProps) {
    const { t } = useTranslation();
    const { languages } = useDirection();
    const queryClient = useQueryClient();

    const creating = account === null;
    const [draft, setDraft] = useState<Draft>(() => draftFrom(account));

    const set = (patch: Partial<Draft>) => setDraft((current) => ({ ...current, ...patch }));

    const save = useMutation({
        mutationFn: async () => {
            if (creating) {
                return createUser({
                    name: draft.name.trim(),
                    email: draft.email.trim(),
                    password: draft.password,
                    password_confirmation: draft.passwordConfirmation,
                    is_active: draft.active,
                    ...(draft.phone.trim() === '' ? {} : { phone: draft.phone.trim() }),
                });
            }

            // Only what changed. Every field is `sometimes`, so an absent key leaves
            // the stored value alone.
            return updateUser(account.id, {
                ...(draft.name.trim() === account.name ? {} : { name: draft.name.trim() }),
                ...(draft.email.trim() === account.email ? {} : { email: draft.email.trim() }),
                ...(draft.phone.trim() === (account.phone ?? '')
                    ? {}
                    : { phone: draft.phone.trim() === '' ? null : draft.phone.trim() }),
                ...(draft.preferredLocale === ''
                    ? {}
                    : { preferred_locale: draft.preferredLocale }),
            });
        },
        onSuccess: async (saved) => {
            await queryClient.invalidateQueries({ queryKey: ['admin-users'] });
            await queryClient.invalidateQueries({ queryKey: ['admin-user'] });
            onSaved(saved);
        },
    });

    const fieldError = (name: string): { error?: string } => {
        const message =
            save.error instanceof ApiError ? save.error.validationDetails?.[name]?.[0] : undefined;

        return message === undefined ? {} : { error: message };
    };

    const emailChanged = !creating && draft.email.trim() !== account.email;
    const passwordsMatch = draft.password === draft.passwordConfirmation;

    const complete = creating
        ? draft.name.trim() !== '' &&
          draft.email.trim() !== '' &&
          draft.password !== '' &&
          passwordsMatch
        : draft.name.trim() !== '' && draft.email.trim() !== '';

    const dirty = creating
        ? true
        : draft.name.trim() !== account.name ||
          draft.email.trim() !== account.email ||
          draft.phone.trim() !== (account.phone ?? '') ||
          draft.preferredLocale !== '';

    return (
        <div className="flex flex-col border border-(--border-default) bg-(--surface-default)">
            <header className="flex items-start justify-between gap-2 border-b border-(--border-default) px-3 py-2.5">
                <div className="min-w-0">
                    <p data-eyebrow>{t('access.users.eyebrow')}</p>
                    <h2 className="truncate text-(length:--text-lg) text-(--text-primary)">
                        {creating ? t('access.users.add') : t('access.users.editing')}
                    </h2>
                </div>
                <Button
                    aria-label={t('access.users.close')}
                    onClick={onClose}
                    size="icon"
                    variant="ghost"
                >
                    <X aria-hidden className="size-4" />
                </Button>
            </header>

            <div className="flex flex-col gap-3 p-3">
                <Field {...fieldError('name')} label={t('access.users.name')} required>
                    {({ id, 'aria-describedby': describedBy, invalid }) => (
                        <Input
                            aria-describedby={describedBy}
                            id={id}
                            invalid={invalid}
                            onChange={(event) => set({ name: event.target.value })}
                            value={draft.name}
                        />
                    )}
                </Field>

                <Field
                    {...fieldError('email')}
                    {...(emailChanged ? { hint: t('access.users.emailChangeHint') } : {})}
                    label={t('access.users.email')}
                    required
                >
                    {({ id, 'aria-describedby': describedBy, invalid }) => (
                        <Input
                            aria-describedby={describedBy}
                            autoComplete="off"
                            data-technical
                            id={id}
                            invalid={invalid}
                            onChange={(event) => set({ email: event.target.value })}
                            type="email"
                            value={draft.email}
                        />
                    )}
                </Field>

                <Field
                    {...fieldError('phone')}
                    hint={t('access.users.phoneHint')}
                    label={t('access.users.phone')}
                >
                    {({ id, 'aria-describedby': describedBy, invalid }) => (
                        <Input
                            aria-describedby={describedBy}
                            data-technical
                            id={id}
                            invalid={invalid}
                            onChange={(event) => set({ phone: event.target.value })}
                            placeholder="+962790000000"
                            value={draft.phone}
                        />
                    )}
                </Field>

                {creating ? (
                    <>
                        <Field
                            {...fieldError('password')}
                            hint={t('access.users.passwordHint')}
                            label={t('access.users.password')}
                            required
                        >
                            {({ id, 'aria-describedby': describedBy, invalid }) => (
                                <Input
                                    aria-describedby={describedBy}
                                    // Never remembered and never suggested: this is a
                                    // credential being set for somebody else.
                                    autoComplete="new-password"
                                    id={id}
                                    invalid={invalid}
                                    onChange={(event) => set({ password: event.target.value })}
                                    type="password"
                                    value={draft.password}
                                />
                            )}
                        </Field>

                        <Field
                            label={t('access.users.passwordConfirm')}
                            required
                            {...(draft.passwordConfirmation === '' || passwordsMatch
                                ? {}
                                : { error: t('access.users.passwordMismatch') })}
                        >
                            {({ id, 'aria-describedby': describedBy, invalid }) => (
                                <Input
                                    aria-describedby={describedBy}
                                    autoComplete="new-password"
                                    id={id}
                                    invalid={invalid}
                                    onChange={(event) =>
                                        set({ passwordConfirmation: event.target.value })
                                    }
                                    type="password"
                                    value={draft.passwordConfirmation}
                                />
                            )}
                        </Field>

                        <label className="flex items-start gap-2">
                            <Checkbox
                                checked={draft.active}
                                className="translate-y-0.5"
                                onChange={(event) => set({ active: event.target.checked })}
                            />
                            <span className="flex flex-col">
                                <span className="text-(--text-primary)">
                                    {t('access.users.activeOnCreate')}
                                </span>
                                <span className="text-(length:--text-sm) text-(--text-muted)">
                                    {t('access.users.activeOnCreateNote')}
                                </span>
                            </span>
                        </label>

                        <Alert tone="info">{t('access.users.createNote')}</Alert>
                    </>
                ) : (
                    <>
                        <div className="flex flex-col gap-1">
                            <label
                                className="text-(length:--text-sm) font-medium text-(--text-secondary)"
                                htmlFor="preferred-locale"
                            >
                                {t('access.users.preferredLocale')}
                            </label>
                            <select
                                className="h-(--field-height) border border-(--border-strong) bg-(--surface-default) px-2 text-(length:--text-base) text-(--text-primary) focus-visible:outline-2 focus-visible:outline-offset-0 focus-visible:outline-(--focus-ring)"
                                id="preferred-locale"
                                onChange={(event) => set({ preferredLocale: event.target.value })}
                                value={draft.preferredLocale}
                            >
                                <option value="">{t('access.users.localeUnchanged')}</option>
                                {/* The platform's active languages, not a pair this
                                    screen knows: the endpoint validates against the
                                    languages table. */}
                                {languages.map((language) => (
                                    <option key={language.code} value={language.code}>
                                        {language.native_name}
                                    </option>
                                ))}
                            </select>
                            <p className="text-(length:--text-sm) text-(--text-muted)">
                                {t('access.users.localeHint')}
                            </p>
                        </div>

                        <p className="text-(length:--text-sm) text-(--text-muted)">
                            {t('access.users.noPasswordEdit')}
                        </p>
                    </>
                )}

                {save.error instanceof ApiError && save.error.validationDetails === null ? (
                    <Alert tone="danger">{save.error.message}</Alert>
                ) : null}

                <div className="flex flex-wrap gap-2">
                    <Button
                        disabled={!complete || !dirty}
                        loading={save.isPending}
                        onClick={() => save.mutate()}
                        variant="primary"
                    >
                        {creating ? t('access.users.create') : t('access.users.save')}
                    </Button>
                    <Button onClick={onClose} variant="ghost">
                        {t('access.users.cancel')}
                    </Button>
                </div>
            </div>
        </div>
    );
}
